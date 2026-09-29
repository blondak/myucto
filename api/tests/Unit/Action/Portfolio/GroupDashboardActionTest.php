<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Portfolio;

use MyInvoice\Action\Portfolio\GroupDashboardAction;
use MyInvoice\Security\EffectiveRole;
use MyInvoice\Service\Portfolio\GroupDashboardService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class GroupDashboardActionTest extends TestCase
{
    public function testUnauthorizedAndClientRequestsCannotReadDashboard(): void
    {
        $service = $this->createMock(GroupDashboardService::class);
        $service->expects(self::never())->method('dashboard');
        $action = new GroupDashboardAction($service);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/portfolio/group-dashboard');
        self::assertSame(403, $action($request, new Response())->getStatusCode());
        self::assertSame(403, $action($request->withAttribute('auth.effective_role', new EffectiveRole(2, 'Synthetic client', 'client', true,
            ['dashboard.portfolio' => 1])), new Response())->getStatusCode());
    }

    public function testInvalidQueryFailsBeforeSourceReads(): void
    {
        $service = $this->createMock(GroupDashboardService::class);
        $service->expects(self::never())->method('dashboard');
        $action = new GroupDashboardAction($service);
        $request = $this->request();
        foreach ([['section' => 'raw'], ['section' => ['overview']], ['months' => 0], ['months' => 37], ['weeks' => 13], ['weeks' => 'abc'],
            ['from' => '2026-01-01'], ['from' => ['2026-01-01'], 'to' => '2026-02-01'],
            ['from' => '2026-02-29', 'to' => '2026-03-01'], ['from' => '2026-02-02', 'to' => '2026-02-01'],
            ['from' => '2023-01-01', 'to' => '2026-01-01'], ['from' => '2026-1-01', 'to' => '2026-02-01']] as $query) {
            self::assertSame(422, $action($request->withQueryParams($query), new Response())->getStatusCode());
        }
    }

    public function testDefaultsAndValidatedBoundsReachService(): void
    {
        $service = $this->createMock(GroupDashboardService::class);
        $calls = [];
        $service->expects(self::exactly(3))->method('dashboard')->willReturnCallback(static function ($request, $section, $months, $weeks, $from, $to) use (&$calls): array {
            $calls[] = [$section, $months, $weeks, $from, $to];
            return ['company_count' => 0];
        });
        $action = new GroupDashboardAction($service);
        self::assertSame(200, $action($this->request(), new Response())->getStatusCode());
        self::assertSame(200, $action($this->request()->withQueryParams(['section' => 'trends', 'months' => '36', 'weeks' => '12']), new Response())->getStatusCode());
        self::assertSame(200, $action($this->request()->withQueryParams(['from' => '2024-02-29', 'to' => '2024-03-02']), new Response())->getStatusCode());
        self::assertSame([['overview', 12, 8, null, null], ['trends', 36, 12, null, null], ['overview', 12, 8, '2024-02-29', '2024-03-02']], $calls);
    }

    public function testPeriodDefaultsToTwelveCalendarMonthsAndClampsLeapComparison(): void
    {
        self::assertSame(['from' => '2023-03-01', 'to' => '2024-02-29', 'previous_from' => '2022-03-01', 'previous_to' => '2023-02-28', 'mode' => 'rolling'],
            GroupDashboardService::period(12, null, null, new \DateTimeImmutable('2024-02-29')));
        self::assertSame(['from' => '2024-02-29', 'to' => '2024-03-02', 'previous_from' => '2023-02-28', 'previous_to' => '2023-03-02', 'mode' => 'custom'],
            GroupDashboardService::period(12, '2024-02-29', '2024-03-02'));
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/api/portfolio/group-dashboard')
            ->withAttribute('auth.effective_role', new EffectiveRole(1, 'Synthetic reader', 'staff', true, ['dashboard.portfolio' => 1]));
    }
}
