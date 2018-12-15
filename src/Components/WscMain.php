<?php

namespace WSSC\Components;

use WSSC\Contracts\CommonsContract;
use WSSC\Contracts\WscCommonsContract;
use WSSC\Exceptions\BadOpcodeException;
use WSSC\Exceptions\BadUriException;
use WSSC\Exceptions\ConnectionException;

/**
 * Class WscMain
 *
 * @package WSSC\Components
 *
 * @property ClientConfig config
 */
class WscMain implements WscCommonsContract
{

    private $socket;
    private $isConnected = false;
    private $isClosing = false;
    private $lastOpcode;
    private $closeStatus;
    private $hugePayload;

    private static $opcodes = [
        CommonsContract::EVENT_TYPE_CONTINUATION => 0,
        CommonsContract::EVENT_TYPE_TEXT         => 1,
        CommonsContract::EVENT_TYPE_BINARY       => 2,
        CommonsContract::EVENT_TYPE_CLOSE        => 8,
        CommonsContract::EVENT_TYPE_PING         => 9,
        CommonsContract::EVENT_TYPE_PONG         => 10,
    ];

    protected $socketUrl = '';
    protected $config;

    /**
     * @throws \InvalidArgumentException
     * @throws BadUriException
     * @throws ConnectionException
     * @throws \Exception
     */
    protected function connect()
    {
        $urlParts = parse_url($this->socketUrl);

        $scheme = $urlParts['scheme'];
        $host = $urlParts['host'];
        $user = isset($urlParts['user']) ? $urlParts['user'] : '';
        $pass = isset($urlParts['pass']) ? $urlParts['pass'] : '';
        $port = isset($urlParts['port']) ? $urlParts['port'] : ($scheme === 'wss' ? 443 : 80);

        $pathWithQuery = $this->getPathWithQuery($urlParts);
        $hostUri = $this->getHostUri($scheme, $host);

        // Set the stream context options if they're already set in the config
        $context = $this->getStreamContext();
        $this->socket = @stream_socket_client(
            $hostUri . ':' . $port, $errno, $errstr, $this->config->getTimeout(), STREAM_CLIENT_CONNECT, $context
        );

        if ($this->socket === false) {
            throw new ConnectionException(
                "Could not open socket to \"$host:$port\": $errstr ($errno).",
                CommonsContract::CLIENT_COULD_NOT_OPEN_SOCKET
            );
        }

        // Set timeout on the stream as well.
        stream_set_timeout($this->socket, $this->config->getTimeout());

        // Generate the WebSocket key.
        $key = $this->generateKey();
        $headers = [
            'Host'                  => $host . ':' . $port,
            'User-Agent'            => 'websocket-client-php',
            'Connection'            => 'Upgrade',
            'Upgrade'               => 'WebSocket',
            'Sec-WebSocket-Key'     => $key,
            'Sec-Websocket-Version' => '13',
        ];

        // Handle basic authentication.
        if ($user || $pass) {
            $headers['authorization'] = 'Basic ' . base64_encode($user . ':' . $pass) . "\r\n";
        }

        // Add and override with headers from options.
        if (!empty($this->config->getHeaders())) {
            $headers = array_merge($headers, $this->config->getHeaders());
        }

        $header = $this->getHeaders($pathWithQuery, $headers);

        // Send headers.
        $this->write($header);

        // Get server response header
        // @todo Handle version switching
        $this->validateResponse($scheme, $host, $pathWithQuery, $key);
        $this->isConnected = true;
    }

    /**
     * @param string $scheme
     * @param string $host
     * @return string
     * @throws BadUriException
     */
    private function getHostUri(string $scheme, string $host): string
    {
        if (in_array($scheme, ['ws', 'wss'], true) === false) {
            throw new BadUriException(
                "Url should have scheme ws or wss, not '$scheme' from URI '$this->socketUrl' .",
                CommonsContract::CLIENT_INCORRECT_SCHEME
            );
        }

        return ($scheme === 'wss' ? 'ssl' : 'tcp') . '://' . $host;
    }

    /**
     * @param string $scheme
     * @param string $host
     * @param string $pathWithQuery
     * @param string $key
     * @throws ConnectionException
     */
    private function validateResponse(string $scheme, string $host, string $pathWithQuery, string $key)
    {
        $response = stream_get_line($this->socket, self::DEFAULT_RESPONSE_HEADER, "\r\n\r\n");
        if (!preg_match(self::SEC_WEBSOCKET_ACCEPT_PTTRN, $response, $matches)) {
            $address = $scheme . '://' . $host . $pathWithQuery;
            throw new ConnectionException(
                "Connection to '{$address}' failed: Server sent invalid upgrade response:\n"
                . $response, CommonsContract::CLIENT_INVALID_UPGRADE_RESPONSE
            );
        }

        $keyAccept = trim($matches[1]);
        $expectedResonse = base64_encode(pack('H*', sha1($key . self::SERVER_KEY_ACCEPT)));
        if ($keyAccept !== $expectedResonse) {
            throw new ConnectionException('Server sent bad upgrade response.',
                CommonsContract::CLIENT_INVALID_UPGRADE_RESPONSE);
        }
    }

    /**
     * @return mixed|resource
     * @throws \InvalidArgumentException
     */
    private function getStreamContext()
    {
        if ($this->config->getContext() !== NULL) {
            // Suppress the error since we'll catch it below
            if (@get_resource_type($this->config->getContext()) === 'stream-context') {
                return $this->config->getContext();
            }

            throw new \InvalidArgumentException(
                'Stream context is invalid', CommonsContract::CLIENT_INVALID_STREAM_CONTEXT
            );
        }

        //return stream_context_create();
        return stream_context_create(['ssl' => [ 'verify_peer' => FALSE ]]);
    }

    /**
     * @param mixed $urlParts
     * @return string
     */
    private function getPathWithQuery($urlParts): string
    {
        $path = isset($urlParts['path']) ? $urlParts['path'] : '/';
        $query = isset($urlParts['query']) ? $urlParts['query'] : '';
        $fragment = isset($urlParts['fragment']) ? $urlParts['fragment'] : '';
        $pathWithQuery = $path;
        if (!empty($query)) {
            $pathWithQuery .= '?' . $query;
        }
        if (!empty($fragment)) {
            $pathWithQuery .= '#' . $fragment;
        }

        return $pathWithQuery;
    }

    /**
     * @param string $pathWithQuery
     * @param array $headers
     * @return string
     */
    private function getHeaders(string $pathWithQuery, array $headers): string
    {
        return 'GET ' . $pathWithQuery . " HTTP/1.1\r\n"
            . implode(
                "\r\n", array_map(
                    function ($key, $value) {
                        return "$key: $value";
                    }, array_keys($headers), $headers
                )
            )
            . "\r\n\r\n";
    }

    /**
     * @return string
     */
    public function getLastOpcode(): string
    {
        return $this->lastOpcode;
    }

    /**
     * @return int
     */
    public function getCloseStatus(): int
    {
        return $this->closeStatus;
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    /**
     * @param int $timeout
     * @param null $microSecs
     * @return WscMain
     */
    public function setTimeout(int $timeout, $microSecs = NULL): WscMain
    {
        $this->config->setTimeout($timeout);
        if ($this->socket && get_resource_type($this->socket) === 'stream') {
            stream_set_timeout($this->socket, $timeout, $microSecs);
        }

        return $this;
    }

    /**
     * Sends message to opened socket connection client->server
     *
     * @param $payload
     * @param string $opcode
     * @throws \InvalidArgumentException
     * @throws BadOpcodeException
     * @throws BadUriException
     * @throws ConnectionException
     * @throws \Exception
     */
    public function send($payload, $opcode = CommonsContract::EVENT_TYPE_TEXT)
    {
        if (!$this->isConnected) {
            $this->connect();
        }
        if (array_key_exists($opcode, self::$opcodes) === false) {
            throw new BadOpcodeException("Bad opcode '$opcode'.  Try 'text' or 'binary'.",
                CommonsContract::CLIENT_BAD_OPCODE);
        }
        // record the length of the payload
        $payloadLength = strlen($payload);

        $fragmentCursor = 0;
        // while we have data to send
        while ($payloadLength > $fragmentCursor) {
            // get a fragment of the payload
            $sub_payload = substr($payload, $fragmentCursor, $this->config->getFragmentSize());

            // advance the cursor
            $fragmentCursor += $this->config->getFragmentSize();

            // is this the final fragment to send?
            $final = $payloadLength <= $fragmentCursor;

            // send the fragment
            $this->sendFragment($final, $sub_payload, $opcode, true);

            // all fragments after the first will be marked a continuation
            $opcode = 'continuation';
        }
    }

    /**
     * @param $final
     * @param $payload
     * @param $opcode
     * @param $masked
     * @throws ConnectionException
     * @throws \Exception
     */
    protected function sendFragment($final, $payload, $opcode, $masked)
    {
        // Binary string for header.
        $frameHeadBin = '';
        // Write FIN, final fragment bit.
        $frameHeadBin .= (bool)$final ? '1' : '0';
        // RSV 1, 2, & 3 false and unused.
        $frameHeadBin .= '000';
        // Opcode rest of the byte.
        $frameHeadBin .= sprintf('%04b', self::$opcodes[$opcode]);
        // Use masking?
        $frameHeadBin .= $masked ? '1' : '0';

        // 7 bits of payload length...
        $payloadLen = strlen($payload);
        if ($payloadLen > self::MAX_BYTES_READ) {
            $frameHeadBin .= decbin(self::MASK_127);
            $frameHeadBin .= sprintf('%064b', $payloadLen);
        } else if ($payloadLen > self::MASK_125) {
            $frameHeadBin .= decbin(self::MASK_126);
            $frameHeadBin .= sprintf('%016b', $payloadLen);
        } else {
            $frameHeadBin .= sprintf('%07b', $payloadLen);
        }

        $frame = '';

        // Write frame head to frame.
        foreach (str_split($frameHeadBin, 8) as $binstr) {
            $frame .= chr(bindec($binstr));
        }
        // Handle masking
        if ($masked) {
            // generate a random mask:
            $mask = '';
            for ($i = 0; $i < 4; $i++) {
                $mask .= chr(random_int(0, 255));
            }
            $frame .= $mask;
        }

        // Append payload to frame:
        for ($i = 0; $i < $payloadLen; $i++) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        $this->write($frame);
    }

    /**
     * Receives message client<-server
     *
     * @return null|string
     * @throws \InvalidArgumentException
     * @throws BadOpcodeException
     * @throws BadUriException
     * @throws ConnectionException
     * @throws \Exception
     */
    public function receive()
    {
        if (!$this->isConnected) {
            $this->connect();
        }
        $this->hugePayload = '';

        $response = NULL;
        while (NULL === $response) {
            $response = $this->receiveFragment();
        }

        return $response;
    }

    /**
     * @return null|string
     * @throws \InvalidArgumentException
     * @throws BadOpcodeException
     * @throws BadUriException
     * @throws ConnectionException
     * @throws \Exception
     */
    protected function receiveFragment()
    {
        // Just read the main fragment information first.
        $data = $this->read(2);

        // Is this the final fragment?  // Bit 0 in byte 0
        /// @todo Handle huge payloads with multiple fragments.
        $final = (bool)(ord($data[0]) & 1 << 7);

        // Parse opcode
        $opcode_int = ord($data[0]) & 31; // Bits 4-7
        $opcode_ints = array_flip(self::$opcodes);
        if (!array_key_exists($opcode_int, $opcode_ints)) {
            throw new ConnectionException("Bad opcode in websocket frame: $opcode_int",
                CommonsContract::CLIENT_BAD_OPCODE);
        }

        $opcode = $opcode_ints[$opcode_int];

        // record the opcode if we are not receiving a continutation fragment
        if ($opcode !== 'continuation') {
            $this->lastOpcode = $opcode;
        }

        $payloadLength = $this->getPayloadLength($data);
        $payload = $this->getPayloadData($data, $payloadLength);

        if ($opcode === CommonsContract::EVENT_TYPE_CLOSE) {
            // Get the close status.
            if ($payloadLength >= 2) {
                $statusBin = $payload[0] . $payload[1];
                $status = bindec(sprintf('%08b%08b', ord($payload[0]), ord($payload[1])));
                $this->closeStatus = $status;
                $payload = substr($payload, 2);

                if (!$this->isClosing) {
                    $this->send($statusBin . 'Close acknowledged: ' . $status,
                        CommonsContract::EVENT_TYPE_CLOSE); // Respond.
                }
            }

            if ($this->isClosing) {
                $this->isClosing = false; // A close response, all done.
            }

            fclose($this->socket);
            $this->isConnected = false;
        }

        if (!$final) {
            $this->hugePayload .= $payload;

            return NULL;
        } // this is the last fragment, and we are processing a huge_payload

        if ($this->hugePayload) {
            $payload = $this->hugePayload .= $payload;
            $this->hugePayload = NULL;
        }

        return $payload;
    }

    /**
     * @param string $data
     * @param int $payloadLength
     * @return string
     * @throws ConnectionException
     */
    private function getPayloadData(string $data, int $payloadLength): string
    {
        // Masking?
        $mask = (bool)(ord($data[1]) >> 7);  // Bit 0 in byte 1
        $payload = '';
        $maskingKey = '';

        // Get masking key.
        if ($mask) {
            $maskingKey = $this->read(4);
        }

        // Get the actual payload, if any (might not be for e.g. close frames.
        if ($payloadLength > 0) {
            $data = $this->read($payloadLength);

            if ($mask) {
                // Unmask payload.
                for ($i = 0; $i < $payloadLength; $i++) {
                    $payload .= ($data[$i] ^ $maskingKey[$i % 4]);
                }
            } else {
                $payload = $data;
            }
        }

        return $payload;
    }

    /**
     * @param string $data
     * @return float|int
     * @throws ConnectionException
     */
    private function getPayloadLength(string $data)
    {
        $payloadLength = (int)ord($data[1]) & self::MASK_127; // Bits 1-7 in byte 1
        if ($payloadLength > self::MASK_125) {
            if ($payloadLength === self::MASK_126) {
                $data = $this->read(2); // 126: Payload is a 16-bit unsigned int
            } else {
                $data = $this->read(8); // 127: Payload is a 64-bit unsigned int
            }
            $payloadLength = bindec(self::sprintB($data));
        }

        return $payloadLength;
    }

    /**
     * Tell the socket to close.
     *
     * @param integer $status http://tools.ietf.org/html/rfc6455#section-7.4
     * @param string $message A closing message, max 125 bytes.
     * @return bool|null|string
     * @throws BadOpcodeException
     */
    public function close(int $status = 1000, string $message = 'ttfn')
    {
        $statusBin = sprintf('%016b', $status);
        $status_str = '';

        foreach (str_split($statusBin, 8) as $binstr) {
            $status_str .= chr(bindec($binstr));
        }

        $this->send($status_str . $message, CommonsContract::EVENT_TYPE_CLOSE);
        $this->isClosing = true;

        return $this->receive(); // Receiving a close frame will close the socket now.
    }

    /**
     * @param $data
     * @throws ConnectionException
     */
    protected function write(string $data)
    {
        $written = fwrite($this->socket, $data);

        if ($written < strlen($data)) {
            throw new ConnectionException(
                "Could only write $written out of " . strlen($data) . ' bytes.',
                CommonsContract::CLIENT_COULD_ONLY_WRITE_LESS
            );
        }
    }

    /**
     * @param int $len
     * @return string
     * @throws ConnectionException
     */
    protected function read(int $len): string
    {
        $data = '';
        while (($dataLen = strlen($data)) < $len) {
            $buff = fread($this->socket, $len - $dataLen);

            if ($buff === false) {
                $metadata = stream_get_meta_data($this->socket);
                throw new ConnectionException(
                    'Broken frame, read ' . strlen($data) . ' of stated '
                    . $len . ' bytes.  Stream state: '
                    . json_encode($metadata), CommonsContract::CLIENT_BROKEN_FRAME
                );
            }

            if ($buff === '') {
                $metadata = stream_get_meta_data($this->socket);
                throw new ConnectionException(
                    'Empty read; connection dead?  Stream state: ' . json_encode($metadata),
                    CommonsContract::CLIENT_EMPTY_READ
                );
            }
            $data .= $buff;
        }

        return $data;
    }

    /**
     * Helper to convert a binary to a string of '0' and '1'.
     *
     * @param $string
     * @return string
     */
    protected static function sprintB(string $string): string
    {
        $return = '';
        $strLen = strlen($string);
        for ($i = 0; $i < $strLen; $i++) {
            $return .= sprintf('%08b', ord($string[$i]));
        }

        return $return;
    }

    /**
     * Sec-WebSocket-Key generator
     *
     * @return string   the 16 character length key
     * @throws \Exception
     */
    private function generateKey(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"$&/()=[]{}0123456789';
        $key = '';
        $chLen = strlen($chars);
        for ($i = 0; $i < self::KEY_GEN_LENGTH; $i++) {
            $key .= $chars[random_int(0, $chLen - 1)];
        }

        return base64_encode($key);
    }

}
