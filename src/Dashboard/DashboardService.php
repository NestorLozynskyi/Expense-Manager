<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Entity\TransactionType;
use App\Entity\User;
use App\Finance\DateFormat;
use App\Finance\Period;
use App\Finance\PeriodResolver;
use App\Finance\TransactionFilter;
use App\Repository\CategoryRepository;
use App\Repository\TransactionRepository;

/**
 * Numbers behind the dashboard, following the approved prototype logic (screens/dashboard.html).
 */
final readonly class DashboardService
{
    public const DONUT_MAX = 6;

    public function __construct(
        private TransactionRepository $transactions,
        private CategoryRepository $categories,
        private PeriodResolver $periods,
    ) {
    }

    /**
     * @return array{income: int, expense: int, balance: int, count: int, incomeCount: int, expenseCount: int,
     *               deltas: array{income: ?Delta, expense: ?Delta, balance: ?Delta}|null, comparisonLabel: ?string}
     */
    public function totals(User $owner, Period $period): array
    {
        $t = $this->transactions->totals($owner, TransactionFilter::forPeriod($period));
        $incomeCount = $this->transactions->totals($owner, TransactionFilter::forPeriod($period, TransactionType::Income))['count'];
        $out = [
            'income' => $t['income'], 'expense' => $t['expense'], 'balance' => $t['income'] - $t['expense'],
            'count' => $t['count'], 'incomeCount' => $incomeCount, 'expenseCount' => $t['count'] - $incomeCount,
            'deltas' => null, 'comparisonLabel' => null,
        ];
        $cmp = $this->periods->comparison($period);
        if ($cmp) {
            $c = $this->transactions->totals($owner, new TransactionFilter($cmp['from'], $cmp['to']));
            $out['comparisonLabel'] = $cmp['label'];
            $out['deltas'] = [
                'income' => Delta::compare($t['income'], $c['income'], $cmp['label'], 'more', 'less'),
                'expense' => Delta::compare($t['expense'], $c['expense'], $cmp['label'], 'more', 'less'),
                'balance' => Delta::compare($out['balance'], $c['income'] - $c['expense'], $cmp['label'], 'higher', 'lower'),
            ];
        }

        return $out;
    }

    /**
     * Expense share by category (top 6 + "N more categories").
     *
     * @return array{total: int, rows: list<array{name: string, color: string, total: int, pct: float}>}
     */
    public function spendingByCategory(User $owner, Period $period): array
    {
        $sums = $this->transactions->sumByCategory($owner, $period->from, $period->to, TransactionType::Expense);
        arsort($sums);
        $total = array_sum($sums);
        $byId = [];
        foreach ($this->categories->findByOwnerAndType($owner, TransactionType::Expense) as $c) {
            $byId[$c->getId()] = $c;
        }
        $rows = [];
        foreach ($sums as $id => $sum) {
            $c = $byId[$id] ?? null;
            $rows[] = ['name' => $c?->getName() ?? '—', 'color' => $c?->getColor() ?? '#98A2B3', 'icon' => $c?->getIcon() ?? 'tag', 'total' => $sum, 'pct' => $total ? round($sum * 1000 / $total) / 10 : 0.0];
        }
        if (\count($rows) > self::DONUT_MAX) {
            $rest = \array_slice($rows, self::DONUT_MAX);
            $rows = \array_slice($rows, 0, self::DONUT_MAX);
            $restTotal = array_sum(array_column($rest, 'total'));
            $rows[] = ['name' => \count($rest).' more '.(1 === \count($rest) ? 'category' : 'categories'), 'color' => '#98A2B3', 'icon' => 'tag', 'total' => $restTotal, 'pct' => round(array_sum(array_column($rest, 'pct')) * 10) / 10];
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Income vs expenses per month. Month periods: last 4 months with data up to the current month.
     * Year/custom: months inside the period that have data (partial months clipped to the range).
     *
     * @return array{months: list<array{month: string, label: string, full: string, income: int, expense: int}>, subtitle: string, note: ?string}
     */
    public function monthly(User $owner, Period $period): array
    {
        $currentMonth = $this->periods->currentMonth();
        if ($period->isMonth()) {
            $to = $this->periods->today()->modify('last day of this month');
            $data = $this->transactions->monthlyTotals($owner, new \DateTimeImmutable('1970-01-01'), $to);
            $data = \array_slice(array_filter($data, static fn ($v, $m) => $m <= $currentMonth, \ARRAY_FILTER_USE_BOTH), -4, null, true);
        } else {
            $data = $this->transactions->monthlyTotals($owner, $period->from, $period->to);
        }
        $months = [];
        $clipped = false;
        foreach ($data as $m => $v) {
            $first = new \DateTimeImmutable($m.'-01');
            if (!$period->isMonth() && ($period->from > $first || $period->to < $first->modify('last day of this month'))) {
                $clipped = true;
            }
            $months[] = ['month' => $m, 'label' => $first->format('M'), 'full' => $first->format('F Y'), 'income' => $v['income'], 'expense' => $v['expense']];
        }
        $note = null;
        if ([] === $months) {
            $subtitle = $period->isMonth() ? 'By month' : $period->range();
        } elseif ('this-year' === $period->key) {
            $subtitle = 'Jan – Dec '.$period->from->format('Y').' · by month';
            if ($months[0]['month'] > $period->from->format('Y-m')) {
                $note = 'Data since '.$months[0]['label'].' '.substr($months[0]['month'], 0, 4).' — earlier months have no transactions, so they are not shown.';
            }
        } elseif ('custom' === $period->key) {
            $subtitle = $period->range().' · by month';
            if ($clipped) {
                $note = 'Partial months only include days inside the selected range.';
            }
        } else {
            $first = $months[0];
            $last = $months[\count($months) - 1];
            $span = $first['month'] === $last['month'] ? $first['full'] : $first['label'].' – '.$last['full'];
            $subtitle = $span.' · last '.\count($months).(1 === \count($months) ? ' month' : ' months');
        }

        return ['months' => $months, 'subtitle' => $subtitle, 'note' => $note];
    }

    public function recent(User $owner, Period $period, int $limit = 6): array
    {
        return $this->transactions->findFiltered($owner, TransactionFilter::forPeriod($period), $limit);
    }

    public function todayLabel(): string
    {
        return DateFormat::medium($this->periods->today());
    }
}
