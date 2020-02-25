<?php

namespace SlaveMarket\Lease;

use Carbon\Carbon;
use SlaveMarket\Master;
use SlaveMarket\MastersRepository;
use SlaveMarket\Slave;
use SlaveMarket\SlaveException;
use SlaveMarket\SlavesRepository;

/**
 * Операция "Арендовать раба"
 *
 * @package SlaveMarket\Lease
 */
class LeaseOperation
{
    /**
     * @var LeaseContractsRepository
     */
    protected $contractsRepository;

    /**
     * @var MastersRepository
     */
    protected $mastersRepository;

    /**
     * @var SlavesRepository
     */
    protected $slavesRepository;

    /**
     * @var array
     */
    protected $intersectedHours;


    /**
     * @var Master
     */
    protected $master;

    /**
     * @var Slave
     */
    protected $slave;

    /**
     * @var LeaseHour[]
     */
    protected $leasingHours;

    /**
     * @var float
     */
    protected $totalPrice;

    /**
     * @var LeaseResponse
     */
    protected $response;

    /**
     * LeaseOperation constructor.
     *
     * @param LeaseContractsRepository $contractsRepo
     * @param MastersRepository $mastersRepo
     * @param SlavesRepository $slavesRepo
     */
    public function __construct(
        LeaseContractsRepository $contractsRepo,
        MastersRepository $mastersRepo,
        SlavesRepository $slavesRepo
    ) {
        $this->contractsRepository = $contractsRepo;
        $this->mastersRepository = $mastersRepo;
        $this->slavesRepository = $slavesRepo;
    }

    /**
     * Выполнить операцию
     *
     * @param LeaseRequest $request
     * @return LeaseResponse
     *
     * TODO не реализован учет того, что раб может быть занят полный день (24 часа) по нескольким контрактам от одного мастера
     */
    public function run(LeaseRequest $request): LeaseResponse
    {
        $this->response = new LeaseResponse();

        $timeTo = Carbon::parse($request->timeTo);
        $timeFrom = Carbon::parse($request->timeFrom);

        if ($timeTo->eq($timeFrom)) {
            $this->response->addError('Не указан диапазон времени аренды');
            return $this->response;
        }

        if ($timeFrom->gt($timeTo)) {
            $buffer = $timeTo;
            $timeTo = $timeFrom;
            $timeFrom = $buffer;
        }

        $this->intersectedHours = [];

        $this->master = $this->mastersRepository->getById($request->masterId);
        $this->slave = $this->slavesRepository->getById($request->slaveId);

        $this->leasingHours = [];
        $this->totalPrice = 0.0;

        if ($timeTo->isSameDay($timeFrom)) {

            $this->checkPartialDay($timeFrom, $timeTo);

        } else {

            // проверка и обработка первого дня, если он неполный
            $observableTime = $timeFrom->copy()->startOfHour();
            $firstDayLastHour = $timeFrom->endOfDay()->startOfHour();
            if ($observableTime->diffInHours($firstDayLastHour) < 23) {
                $this->checkPartialDay($observableTime, $firstDayLastHour);
            }

            // обработка полных дней
            $dayDifference = $observableTime->startOfDay()->diffInDays($timeTo->startOfDay());
            while ($dayDifference-- >= 1) {

                $dateFrom = $observableTime->format(LeaseContractsRepository::DATE_FORMAT);
                $dateTo = $observableTime->copy()->addDay()->format(LeaseContractsRepository::DATE_FORMAT);
                $existingContracts = $this->contractsRepository->getForSlave($this->slave->getId(), $dateFrom, $dateTo) ?? [];

                $hourDiff = $observableTime->diffInHours($timeTo->startOfDay()) + 1;
                $this->addLeasingHours($hourDiff, $observableTime, $existingContracts);
            }

            // проверка и обработка последнего дня, если он неполный
            $lastDayLastHour = $timeTo->startOfHour();
            if ($observableTime->diffInHours($lastDayLastHour) < 23) {
                $this->checkPartialDay($observableTime, $lastDayLastHour);
            }
        }

        if ($this->intersectedHours) {
            $hoursStrings = array_map(
                function (LeaseHour $hour) {
                    return '"' . $hour->getDateString() . '"';
                },
                $this->intersectedHours
            );
            $hoursString = implode(', ', $hoursStrings);
            $errorText = (new SlaveException($this->slave, 'занят. Занятые часы: ' . $hoursString))->getMessage();
            $this->response->addError($errorText);
        }

        if (!$this->response->getErrors()) {
            $contract = new LeaseContract($this->master, $this->slave, $this->totalPrice, $this->leasingHours);
            $this->response->setLeaseContract($contract);
        }

        return $this->response;
    }

    /**
     * Обработка неполного дня
     *
     * @param Carbon $from
     * @param Carbon $to
     */
    protected function checkPartialDay(Carbon $from, Carbon $to)
    {
        $hourDiff = $to->format('H') - $from->format('H') + 1;

        $dateFrom = $from->format(LeaseContractsRepository::DATE_FORMAT);
        $dateTo = $to->format(LeaseContractsRepository::DATE_FORMAT);
        $existingContracts = $this->contractsRepository->getForSlave($this->slave->getId(), $dateFrom, $dateTo) ?? [];

        $leasedHours = 0;
        foreach ($existingContracts as $contract) {
            $leasedHours += count($contract->leasedHours);
        }

        if ($hourDiff + $leasedHours > Slave::WORK_DAY_LIMIT_IN_HOURS && $hourDiff < 24) {
            $errorText = (new SlaveException($this->slave,
                'не может работать больше ' . Slave::WORK_DAY_LIMIT_IN_HOURS . ' часов'))->getMessage();
            $this->response->addError($errorText);
        } else {
            $this->addLeasingHours($hourDiff, $from, $existingContracts);
        }
    }


    /**
     * Расчет арендуемых часов $hourDiff с учетом уже арендованных часов со времени $observableTime
     *
     * @param int $hourDiff
     * @param Carbon $observableTime
     * @param array $existingContracts
     */
    protected function addLeasingHours(int $hourDiff, Carbon $observableTime, array $existingContracts): void
    {
        $this->totalPrice += min($hourDiff, Slave::WORK_DAY_LIMIT_IN_HOURS) * $this->slave->getPricePerHour();

        while ($hourDiff--) {
            $leasingHour = new LeaseHour($observableTime->format(LeaseHour::TIME_FORMAT));
            $this->leasingHours [] = $leasingHour;

            foreach ($existingContracts as $contract) {
                if ($this->master->isVIP() && !$contract->master->isVIP()) {
                    continue;
                }
                foreach ($contract->leasedHours as $leasedHour) {
                    if ($leasingHour->equals($leasedHour)) {
                        $this->intersectedHours [] = $leasedHour;
                    }
                }
            }
            $observableTime->addHour();
        }
        $observableTime->subHour();
    }
}
