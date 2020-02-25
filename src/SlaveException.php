<?php

namespace SlaveMarket;

use Exception;

class SlaveException extends Exception
{
    /**
     * SlaveException constructor.
     * @param Slave $slave
     * @param string $message
     */
    public function __construct(Slave $slave, string $message)
    {
        $this->slave = $slave;
        $this->message = 'Ошибка. Раб #' . $slave->getId() . ' "' . $slave->getName() . '" ' . $message;
    }
}
