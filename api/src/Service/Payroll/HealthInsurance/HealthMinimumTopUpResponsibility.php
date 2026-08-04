<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\HealthInsurance;

enum HealthMinimumTopUpResponsibility: string
{
    case Employee = 'employee';
    case EmployerObstacleVerified = 'employer_obstacle_verified';
    case Unverified = 'unverified';
}
