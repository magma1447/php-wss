<?php

namespace WSSC\Contracts;

/**
 *
 * @author Arthur Kushman
 */
interface ConnectionContract
{

    public function send($data);

    public function close();

    public function getUniqueSocketId(): int;

    public function getPeerName(): string;
}
