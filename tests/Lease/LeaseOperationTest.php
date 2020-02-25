<?php

namespace SlaveMarket\Lease;

use PHPUnit\Framework\TestCase;
use SlaveMarket\Master;
use SlaveMarket\MastersRepository;
use SlaveMarket\Slave;
use SlaveMarket\SlavesRepository;

/**
 * Тесты операции аренды раба
 *
 * @package SlaveMarket\Lease
 */
class LeaseOperationTest extends TestCase
{
    /**
     * Stub репозитория хозяев
     *
     * @param Master[] ...$masters
     * @return MastersRepository
     */
    private function makeFakeMasterRepository(...$masters): MastersRepository
    {
        $mastersRepository = $this->prophesize(MastersRepository::class);
        foreach ($masters as $master) {
            $mastersRepository->getById($master->getId())->willReturn($master);
        }

        return $mastersRepository->reveal();
    }

    /**
     * Stub репозитория рабов
     *
     * @param Slave[] ...$slaves
     * @return SlavesRepository
     */
    private function makeFakeSlaveRepository(...$slaves): SlavesRepository
    {
        $slavesRepository = $this->prophesize(SlavesRepository::class);
        foreach ($slaves as $slave) {
            $slavesRepository->getById($slave->getId())->willReturn($slave);
        }

        return $slavesRepository->reveal();
    }

    /**
     * Если раб занят, то арендовать его не получится
     */
    public function test_periodIsBusy_failedWithOverlapInfo()
    {
        // -- Arrange
        {
            // Хозяева
            $master1 = new Master(1, 'Господин Боб');
            $master2 = new Master(2, 'сэр Вонючка');
            $masterRepo = $this->makeFakeMasterRepository($master1, $master2);

            // Раб
            $slave1 = new Slave(1, 'Уродливый Фред', 20);
            $slaveRepo = $this->makeFakeSlaveRepository($slave1);

            // Договор аренды. 1й хозяин арендовал раба
            $leaseContract1 = new LeaseContract($master1, $slave1, 80, [
                new LeaseHour('2017-01-01 00'),
                new LeaseHour('2017-01-01 01'),
                new LeaseHour('2017-01-01 02'),
                new LeaseHour('2017-01-01 03'),
            ]);

            // Stub репозитория договоров
            $contractsRepo = $this->prophesize(LeaseContractsRepository::class);
            $contractsRepo
                ->getForSlave($slave1->getId(), '2017-01-01', '2017-01-01')
                ->willReturn([$leaseContract1]);

            // Запрос на новую аренду. 2й хозяин выбрал занятое время
            $leaseRequest = new LeaseRequest();
            $leaseRequest->masterId = $master2->getId();
            $leaseRequest->slaveId = $slave1->getId();
            $leaseRequest->timeFrom = '2017-01-01 01:30:00';
            $leaseRequest->timeTo = '2017-01-01 02:01:00';

            // Операция аренды
            $leaseOperation = new LeaseOperation($contractsRepo->reveal(), $masterRepo, $slaveRepo);
        }

        // -- Act
        $response = $leaseOperation->run($leaseRequest);

        // -- Assert
        $expectedErrors = ['Ошибка. Раб #1 "Уродливый Фред" занят. Занятые часы: "2017-01-01 01", "2017-01-01 02"'];

        $this->assertArraySubset($expectedErrors, $response->getErrors());
        $this->assertNull($response->getLeaseContract());
    }

    public function test_idleSlaveAlmostFullDay_failedWithOverlapInfo()
    {
        // -- Arrange
        {
            // Хозяева
            $master1 = new Master(1, 'Рамси Сноу');
            $masterRepo = $this->makeFakeMasterRepository($master1);

            // Раб
            $slave1 = new Slave(1, 'Вонючка', 20);
            $slaveRepo = $this->makeFakeSlaveRepository($slave1);

            $contractsRepo = $this->prophesize(LeaseContractsRepository::class);
            $contractsRepo
                ->getForSlave($slave1->getId(), '2017-01-01', '2017-01-01')
                ->willReturn([]);

            // Запрос на новую аренду
            $leaseRequest = new LeaseRequest();
            $leaseRequest->masterId = $master1->getId();
            $leaseRequest->slaveId = $slave1->getId();
            $leaseRequest->timeFrom = '2017-01-01 00:00:00';
            $leaseRequest->timeTo = '2017-01-01 22:59:00';

            // Операция аренды
            $leaseOperation = new LeaseOperation($contractsRepo->reveal(), $masterRepo, $slaveRepo);
        }

        // -- Act
        $response = $leaseOperation->run($leaseRequest);

        // -- Assert
        $expectedErrors = ['Ошибка. Раб #1 "Вонючка" не может работать больше 16 часов'];

        $this->assertArraySubset($expectedErrors, $response->getErrors());
        $this->assertNull($response->getLeaseContract());
    }

    public function test_idleSlaveFullDay_successfullyLeased()
    {
        // -- Arrange
        {
            // Хозяева
            $master1 = new Master(1, 'Рамси Сноу');
            $masterRepo = $this->makeFakeMasterRepository($master1);

            // Раб
            $slave1 = new Slave(1, 'Вонючка', 20);
            $slaveRepo = $this->makeFakeSlaveRepository($slave1);

            $contractsRepo = $this->prophesize(LeaseContractsRepository::class);
            $contractsRepo
                ->getForSlave($slave1->getId(), '2017-01-01', '2017-01-01')
                ->willReturn([]);

            // Запрос на новую аренду
            $leaseRequest = new LeaseRequest();
            $leaseRequest->masterId = $master1->getId();
            $leaseRequest->slaveId = $slave1->getId();
            $leaseRequest->timeFrom = '2017-01-01 00:00:00';
            $leaseRequest->timeTo = '2017-01-01 23:59:00';

            // Операция аренды
            $leaseOperation = new LeaseOperation($contractsRepo->reveal(), $masterRepo, $slaveRepo);
        }

        // -- Act
        $response = $leaseOperation->run($leaseRequest);

        // -- Assert
        // -- Assert
        $this->assertEmpty($response->getErrors());
        $this->assertInstanceOf(LeaseContract::class, $response->getLeaseContract());
        $this->assertEquals(20 * 16, $response->getLeaseContract()->price);
    }

    /**
     * Если раб бездельничает, то его легко можно арендовать
     */
    public function test_idleSlave_successfullyLeased()
    {
        // -- Arrange
        {
            // Хозяева
            $master1 = new Master(1, 'Господин Боб');
            $masterRepo = $this->makeFakeMasterRepository($master1);

            // Раб
            $slave1 = new Slave(1, 'Уродливый Фред', 20);
            $slaveRepo = $this->makeFakeSlaveRepository($slave1);

            $contractsRepo = $this->prophesize(LeaseContractsRepository::class);
            $contractsRepo
                ->getForSlave($slave1->getId(), '2017-01-01', '2017-01-01')
                ->willReturn([]);

            // Запрос на новую аренду
            $leaseRequest = new LeaseRequest();
            $leaseRequest->masterId = $master1->getId();
            $leaseRequest->slaveId = $slave1->getId();
            $leaseRequest->timeFrom = '2017-01-01 01:30:00';
            $leaseRequest->timeTo = '2017-01-01 02:01:00';

            // Операция аренды
            $leaseOperation = new LeaseOperation($contractsRepo->reveal(), $masterRepo, $slaveRepo);
        }

        // -- Act
        $response = $leaseOperation->run($leaseRequest);

        // -- Assert
        $this->assertEmpty($response->getErrors());
        $this->assertInstanceOf(LeaseContract::class, $response->getLeaseContract());
        $this->assertEquals(40, $response->getLeaseContract()->price);
    }

    /**
     * Если раб бездельничает, то его легко можно арендовать
     */
    public function test_idleSlaveOneHourInOneDay_successfullyLeased()
    {
        // -- Arrange
        {
            // Хозяева
            $master1 = new Master(1, 'Рамси Сноу');
            $masterRepo = $this->makeFakeMasterRepository($master1);

            // Раб
            $slave1 = new Slave(1, 'Вонючка', 20);
            $slaveRepo = $this->makeFakeSlaveRepository($slave1);

            $contractsRepo = $this->prophesize(LeaseContractsRepository::class);
            $contractsRepo
                ->getForSlave($slave1->getId(), '2017-01-01', '2017-01-01')
                ->willReturn([]);

            // Запрос на новую аренду
            $leaseRequest = new LeaseRequest();
            $leaseRequest->masterId = $master1->getId();
            $leaseRequest->slaveId = $slave1->getId();
            $leaseRequest->timeFrom = '2017-01-01 00:00:00';
            $leaseRequest->timeTo = '2017-01-01 00:01:00';

            // Операция аренды
            $leaseOperation = new LeaseOperation($contractsRepo->reveal(), $masterRepo, $slaveRepo);
        }

        // -- Act
        $response = $leaseOperation->run($leaseRequest);

        // -- Assert
        $this->assertEmpty($response->getErrors());
        $this->assertInstanceOf(LeaseContract::class, $response->getLeaseContract());
        $this->assertEquals(20, $response->getLeaseContract()->price);
    }

    public function test_idleSlaveFullDayAndOnePartial_successfullyLeased()
    {
        // -- Arrange
        {
            // Хозяева
            $master1 = new Master(1, 'Рамси Сноу');
            $masterRepo = $this->makeFakeMasterRepository($master1);

            // Раб
            $slave1 = new Slave(1, 'Вонючка', 20);
            $slaveRepo = $this->makeFakeSlaveRepository($slave1);

            $contractsRepo = $this->prophesize(LeaseContractsRepository::class);
            $contractsRepo
                ->getForSlave($slave1->getId(), '2017-01-01', '2017-01-01')
                ->willReturn([]);

            $contractsRepo
                ->getForSlave($slave1->getId(), '2017-01-02', '2017-01-02')
                ->willReturn([]);

            $contractsRepo
                ->getForSlave($slave1->getId(), '2017-01-01', '2017-01-02')
                ->willReturn([]);

            // Запрос на новую аренду
            $leaseRequest = new LeaseRequest();
            $leaseRequest->masterId = $master1->getId();
            $leaseRequest->slaveId = $slave1->getId();
            $leaseRequest->timeFrom = '2017-01-01 00:00:00';
            $leaseRequest->timeTo = '2017-01-02 00:01:00';

            // Операция аренды
            $leaseOperation = new LeaseOperation($contractsRepo->reveal(), $masterRepo, $slaveRepo);
        }

        // -- Act
        $response = $leaseOperation->run($leaseRequest);

        // -- Assert
        $this->assertEmpty($response->getErrors());
        $this->assertInstanceOf(LeaseContract::class, $response->getLeaseContract());
        $this->assertEquals(20 * 17, $response->getLeaseContract()->price);
    }

    public function test_idleSlaveVip_successfullyLeased()
    {
        // -- Arrange
        {
            // Хозяева
            $master1 = new Master(1, 'Рамси Сноу', true);
            $masterRepo = $this->makeFakeMasterRepository($master1);

            $master2 = new Master(1, 'Джон Сноу', false);
            $masterRepo = $this->makeFakeMasterRepository($master1);

            // Раб
            $slave1 = new Slave(1, 'Вонючка', 20);
            $slaveRepo = $this->makeFakeSlaveRepository($slave1);

            // Договор аренды. 2й хозяин арендовал раба
            $leaseContract1 = new LeaseContract($master2, $slave1, 80, [
                new LeaseHour('2017-01-01 00'),
                new LeaseHour('2017-01-01 01'),
                new LeaseHour('2017-01-01 02'),
                new LeaseHour('2017-01-01 03'),
            ]);

            $contractsRepo = $this->prophesize(LeaseContractsRepository::class);
            $contractsRepo
                ->getForSlave($slave1->getId(), '2017-01-01', '2017-01-01')
                ->willReturn([$leaseContract1]);

            // Запрос на новую аренду
            $leaseRequest = new LeaseRequest();
            $leaseRequest->masterId = $master1->getId();
            $leaseRequest->slaveId = $slave1->getId();
            $leaseRequest->timeFrom = '2017-01-01 00:00:00';
            $leaseRequest->timeTo = '2017-01-01 01:00:00';

            // Операция аренды
            $leaseOperation = new LeaseOperation($contractsRepo->reveal(), $masterRepo, $slaveRepo);
        }

        // -- Act
        $response = $leaseOperation->run($leaseRequest);

        $this->assertEmpty($response->getErrors());
        $this->assertInstanceOf(LeaseContract::class, $response->getLeaseContract());
        $this->assertEquals(40, $response->getLeaseContract()->price);
    }

    public function test_idleSlaveVip_failedWithOverlapInfo()
    {
        // -- Arrange
        {
            // Хозяева
            $master1 = new Master(1, 'Рамси Сноу', true);
            $masterRepo = $this->makeFakeMasterRepository($master1);

            $master2 = new Master(1, 'Джон Сноу', false);
            $masterRepo = $this->makeFakeMasterRepository($master1);

            // Раб
            $slave1 = new Slave(1, 'Вонючка', 20);
            $slaveRepo = $this->makeFakeSlaveRepository($slave1);

            // Договор аренды. 1й хозяин арендовал раба
            $leaseContract1 = new LeaseContract($master1, $slave1, 80, [
                new LeaseHour('2017-01-01 00'),
                new LeaseHour('2017-01-01 01'),
                new LeaseHour('2017-01-01 02'),
                new LeaseHour('2017-01-01 03'),
            ]);

            $contractsRepo = $this->prophesize(LeaseContractsRepository::class);
            $contractsRepo
                ->getForSlave($slave1->getId(), '2017-01-01', '2017-01-01')
                ->willReturn([$leaseContract1]);

            // Запрос на новую аренду
            $leaseRequest = new LeaseRequest();
            $leaseRequest->masterId = $master2->getId();
            $leaseRequest->slaveId = $slave1->getId();
            $leaseRequest->timeFrom = '2017-01-01 00:00:00';
            $leaseRequest->timeTo = '2017-01-01 01:00:00';

            // Операция аренды
            $leaseOperation = new LeaseOperation($contractsRepo->reveal(), $masterRepo, $slaveRepo);
        }

        // -- Act
        $response = $leaseOperation->run($leaseRequest);

        // -- Assert
        $expectedErrors = ['Ошибка. Раб #1 "Вонючка" занят. Занятые часы: "2017-01-01 00", "2017-01-01 01"'];

        $this->assertArraySubset($expectedErrors, $response->getErrors());
        $this->assertNull($response->getLeaseContract());
    }

    public function test_idleSlaveTired_failedWithOverlapInfo()
    {
        // -- Arrange
        {
            // Хозяева
            $master1 = new Master(1, 'Рамси Сноу', true);
            $masterRepo = $this->makeFakeMasterRepository($master1);

            $master2 = new Master(1, 'Джон Сноу', false);
            $masterRepo = $this->makeFakeMasterRepository($master1);

            // Раб
            $slave1 = new Slave(1, 'Вонючка', 20);
            $slaveRepo = $this->makeFakeSlaveRepository($slave1);

            // Договор аренды. 1й хозяин арендовал раба
            $leaseContract1 = new LeaseContract($master1, $slave1, 80, [
                new LeaseHour('2017-01-01 23'),
            ]);

            $contractsRepo = $this->prophesize(LeaseContractsRepository::class);
            $contractsRepo
                ->getForSlave($slave1->getId(), '2017-01-01', '2017-01-01')
                ->willReturn([$leaseContract1]);

            // Запрос на новую аренду
            $leaseRequest = new LeaseRequest();
            $leaseRequest->masterId = $master2->getId();
            $leaseRequest->slaveId = $slave1->getId();
            $leaseRequest->timeFrom = '2017-01-01 00:00:00';
            $leaseRequest->timeTo = '2017-01-01 15:00:00';

            // Операция аренды
            $leaseOperation = new LeaseOperation($contractsRepo->reveal(), $masterRepo, $slaveRepo);
        }

        // -- Act
        $response = $leaseOperation->run($leaseRequest);

        // -- Assert
        $expectedErrors = ['Ошибка. Раб #1 "Вонючка" не может работать больше ' . Slave::WORK_DAY_LIMIT_IN_HOURS . ' часов'];

        $this->assertArraySubset($expectedErrors, $response->getErrors());
        $this->assertNull($response->getLeaseContract());
    }
}