<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * Demo data set from the approved design (assets/data.js), expressed relative to "today":
 * month offset (-3 … 0), day of month, category key, amount in cents, note.
 * Current-month rows are clamped to today so the current month always shows one budget over
 * the limit (Dining out) and one near the limit (Transport).
 */
final class DemoData
{
    /** @var list<array{int, int, string, int, string}> */
    public const TRANSACTIONS = [
        [-3, 1, 'salary', 420000, 'Salary — Northwind Labs'],
        [-3, 1, 'housing', 145000, 'Rent'],
        [-3, 2, 'groceries', 8642, 'Weekly groceries — FreshMart'],
        [-3, 4, 'subscriptions', 1549, 'Netflix'],
        [-3, 5, 'transport', 9800, 'Monthly transit pass'],
        [-3, 7, 'dining', 3860, 'Dinner with Sam — Osteria'],
        [-3, 9, 'groceries', 7218, 'Weekly groceries — FreshMart'],
        [-3, 10, 'utilities', 6430, 'Electricity bill'],
        [-3, 12, 'freelance', 65000, 'Landing page project — Acme Co.'],
        [-3, 14, 'health', 2500, 'Pharmacy — allergy meds'],
        [-3, 15, 'entertainment', 4200, 'Concert tickets'],
        [-3, 16, 'groceries', 9105, 'Weekly groceries'],
        [-3, 18, 'shopping', 12999, 'Running shoes'],
        [-3, 20, 'utilities', 4500, 'Internet — FiberNet'],
        [-3, 22, 'dining', 2475, 'Lunch — Ramen Bar'],
        [-3, 23, 'groceries', 6890, 'Farmers market'],
        [-3, 25, 'travel', 21400, 'Train tickets — weekend trip'],
        [-3, 27, 'subscriptions', 1199, 'Spotify'],
        [-3, 29, 'dining', 5620, 'Brunch — Café Lumen'],
        [-3, 31, 'interest', 387, 'Savings interest'],
        [-2, 1, 'salary', 420000, 'Salary — Northwind Labs'],
        [-2, 1, 'housing', 145000, 'Rent'],
        [-2, 3, 'groceries', 9433, 'Weekly groceries — FreshMart'],
        [-2, 4, 'subscriptions', 1549, 'Netflix'],
        [-2, 5, 'transport', 9800, 'Monthly transit pass'],
        [-2, 6, 'education', 18900, 'Online course — Data Analysis'],
        [-2, 8, 'dining', 4780, 'Team dinner'],
        [-2, 10, 'utilities', 5875, 'Electricity bill'],
        [-2, 11, 'groceries', 7764, 'Weekly groceries'],
        [-2, 13, 'health', 6000, 'Dentist co-pay'],
        [-2, 15, 'freelance', 42000, 'Logo refresh — Bloom Studio'],
        [-2, 17, 'groceries', 8310, 'Weekly groceries — FreshMart'],
        [-2, 19, 'entertainment', 2850, 'Cinema'],
        [-2, 20, 'utilities', 4500, 'Internet — FiberNet'],
        [-2, 21, 'shopping', 6499, 'Desk lamp'],
        [-2, 23, 'dining', 3340, 'Pizza night'],
        [-2, 25, 'refunds', 4500, 'Refund — returned jacket'],
        [-2, 26, 'groceries', 7025, 'Weekly groceries'],
        [-2, 27, 'subscriptions', 1199, 'Spotify'],
        [-2, 29, 'transport', 3250, 'Taxi — airport'],
        [-2, 30, 'interest', 392, 'Savings interest'],
        [-1, 1, 'salary', 420000, 'Salary — Northwind Labs'],
        [-1, 1, 'housing', 145000, 'Rent'],
        [-1, 2, 'groceries', 8876, 'Weekly groceries — FreshMart'],
        [-1, 4, 'subscriptions', 1549, 'Netflix'],
        [-1, 5, 'transport', 9800, 'Monthly transit pass'],
        [-1, 6, 'dining', 5230, 'Birthday dinner — Saffron'],
        [-1, 9, 'groceries', 7948, 'Weekly groceries'],
        [-1, 10, 'utilities', 7120, 'Electricity bill'],
        [-1, 11, 'dining', 4190, 'Sushi with Mia'],
        [-1, 14, 'freelance', 78000, 'Dashboard UI — Kite Analytics'],
        [-1, 15, 'shopping', 11240, 'Autumn jacket'],
        [-1, 16, 'groceries', 9215, 'Weekly groceries — FreshMart'],
        [-1, 18, 'entertainment', 3600, 'Board game café'],
        [-1, 19, 'dining', 6845, 'Weekend brunch'],
        [-1, 20, 'utilities', 4500, 'Internet — FiberNet'],
        [-1, 22, 'health', 1860, 'Pharmacy'],
        [-1, 23, 'groceries', 7432, 'Weekly groceries'],
        [-1, 25, 'dining', 4995, 'Thai takeaway'],
        [-1, 26, 'gifts', 10000, 'Birthday gift from Grandma'],
        [-1, 27, 'subscriptions', 1199, 'Spotify'],
        [-1, 28, 'transport', 1880, 'Taxi — late night'],
        [-1, 30, 'other', 3000, 'Charity donation'],
        [-1, 31, 'interest', 405, 'Savings interest'],
        [0, 1, 'salary', 420000, 'Salary — Northwind Labs'],
        [0, 1, 'housing', 145000, 'Rent'],
        [0, 1, 'subscriptions', 1549, 'Netflix'],
        [0, 2, 'dining', 6480, 'Sunday brunch — Café Lumen'],
        [0, 2, 'groceries', 9620, 'Weekly groceries — FreshMart'],
        [0, 3, 'transport', 9800, 'Monthly transit pass'],
        [0, 3, 'dining', 4210, 'Lunch with team — Ramen Bar'],
        [0, 4, 'entertainment', 2400, 'Movie night'],
        [0, 4, 'dining', 7950, 'Anniversary dinner — Saffron'],
        [0, 5, 'groceries', 4615, 'Bakery & produce'],
        [0, 5, 'freelance', 35000, 'Icon set — Kite Analytics'],
    ];

    /** Budgets for the previous and the current month (cents). */
    public const OVERALL_LIMIT = 320000;
    public const CATEGORY_LIMITS = [
        'groceries' => 45000,
        'dining' => 15000,
        'transport' => 12000,
        'entertainment' => 8000,
        'shopping' => 15000,
        'subscriptions' => 4000,
    ];
}
