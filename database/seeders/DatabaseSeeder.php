<?php

namespace Database\Seeders;

use App\Filament\Resources\Shop\Orders\OrderResource;
use App\Models\Address;
use App\Models\Comment;
use App\Models\Shop\Brand;
use App\Models\Shop\Customer;
use App\Models\Shop\Order;
use App\Models\Shop\OrderItem;
use App\Models\Shop\Payment;
use App\Models\Shop\Product;
use App\Models\Shop\ProductCategory;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Helper\ProgressBar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // +10% random variation so seeded counts aren't perfectly round
        $vary = fn (int $n): int => rand($n, (int) ceil($n * 1.1));

        // Clear images
        $publicDisk = Storage::disk('public');
        collect($publicDisk->allDirectories())->each(fn ($dir) => $publicDisk->deleteDirectory($dir));
        collect($publicDisk->allFiles())->each(fn ($file) => $publicDisk->delete($file));

        // Admin
        $this->command->warn(PHP_EOL . 'Creating admin user...');
        $user = $this->withProgressBar(1, fn () => User::factory(1)->create([
            'name' => 'Demo User',
            'email' => 'admin@filamentphp.com',
            'password' => Hash::make('demo.Filament@2021!'),
        ]));
        $this->command->info('Admin user created.');

        // Shop
        $this->command->warn(PHP_EOL . 'Creating brands...');
        $brands = $this->withProgressBar($vary(20), fn () => Brand::factory()->count(20)
            ->has(Address::factory()->count(rand(1, 3)))
            ->create());
        Brand::query()->update(['sort' => new Expression('id')]);
        $this->command->info('Brands created.');

        $this->command->warn(PHP_EOL . 'Creating product categories...');
        $categories = $this->withProgressBar($vary(20), fn () => ProductCategory::factory(1)
            ->has(
                ProductCategory::factory()->count(3),
                'children'
            )->create());
        $this->command->info('Product categories created.');

        $this->command->warn(PHP_EOL . 'Creating customers...');

        // Weighted monthly distribution for realistic customer growth chart.
        // Index 0 = 24 months ago → index 23 = this month.
        // Slow early trickle → steady ramp → recent acceleration with seasonal dips.
        $customerMonthlyWeights = [
            // 24–13 months ago (early days, slow organic growth)
            8, 10, 12, 14, 18, 22, 20, 25, 28, 24, 35, 15,
            // 12–1 months ago (growth phase after marketing push)
            38, 30, 50, 55, 70, 85, 95, 80, 100, 75, 130, 45,
        ];
        $customerTotalWeight = array_sum($customerMonthlyWeights);

        $pickCustomerMonth = function () use ($customerMonthlyWeights, $customerTotalWeight): int {
            $rand = rand(1, $customerTotalWeight);
            $cumulative = 0;

            foreach ($customerMonthlyWeights as $i => $weight) {
                $cumulative += $weight;

                if ($rand <= $cumulative) {
                    return $i;
                }
            }

            return count($customerMonthlyWeights) - 1;
        };

        $customers = $this->withProgressBar($vary(1000), fn () => Customer::factory(1)
            ->state(function () use ($pickCustomerMonth) {
                $monthIndex = $pickCustomerMonth();
                $monthsAgo = 23 - $monthIndex;
                $start = now()->subMonths($monthsAgo)->startOfMonth();
                $end = min(now(), now()->subMonths($monthsAgo)->endOfMonth());

                $createdAt = fake()->dateTimeBetween($start, $end);

                return [
                    'created_at' => $createdAt,
                    'updated_at' => fake()->dateTimeBetween($createdAt, 'now'),
                ];
            })
            ->has(Address::factory()->count(rand(1, 3)))
            ->create());
        $this->command->info('Customers created.');

        $this->command->warn(PHP_EOL . 'Creating products...');
        $products = $this->withProgressBar($vary(50), fn () => Product::factory(1)
            ->sequence(fn ($sequence) => ['brand_id' => $brands->random(1)->first()->id])
            ->hasAttached($categories->random(rand(3, 6)), ['created_at' => now(), 'updated_at' => now()])
            ->has(
                Comment::factory()->count(rand(10, 20))
                    ->state(fn (array $attributes, Product $product) => [
                        'customer_id' => $customers->random(1)->first()->id,
                        'created_at' => fake()->dateTimeBetween('-2 years', 'now'),
                    ]),
            )
            ->create());
        $products->first()->update(['is_visible' => true]);
        $this->command->info('Products created.');

        // Anomaly products for interesting scatter chart (Price vs Stock Quantity)
        $anomalies = [
            ['price' => 450, 'qty' => 85],  // Expensive but high stock (bulk premium)
            ['price' => 480, 'qty' => 60],  // Expensive but decent stock
            ['price' => 12, 'qty' => 3],    // Cheap but rare (clearance)
            ['price' => 8, 'qty' => 2],     // Cheap but rare
            ['price' => 250, 'qty' => 70],  // Mid-high price, very high stock
        ];

        foreach ($anomalies as $anomaly) {
            $products->push(Product::factory()
                ->state([
                    'old_price' => $anomaly['price'],
                    'price' => $anomaly['price'] * fake()->randomFloat(2, 0.7, 1.0),
                    'cost' => $anomaly['price'] * fake()->randomFloat(2, 0.3, 0.6),
                    'qty' => $anomaly['qty'],
                ])
                ->sequence(fn ($sequence) => ['brand_id' => $brands->random(1)->first()->id])
                ->hasAttached($categories->random(rand(3, 6)), ['created_at' => now(), 'updated_at' => now()])
                ->create());
        }

        $this->command->warn(PHP_EOL . 'Creating orders...');

        // Weighted monthly distribution for interesting charts.
        // Index 0 = 24 months ago → index 23 = this month.
        // Two "years" of seasonal patterns so cumulative charts have historical baseline.
        $monthlyWeights = [
            // 24–13 months ago (historical baseline, lower volume)
            25, 20, 30, 35, 45, 55, 65, 60, 50, 40, 80, 30,
            // 12–1 months ago (recent year, higher volume)
            32, 46, 38, 72, 58, 96, 122, 88, 64, 110, 150, 54,
        ];
        $totalWeight = array_sum($monthlyWeights);

        // Higher-value orders during peak months (repeating seasonal pattern).
        $monthlyPriceRanges = [
            [100, 1000], [100, 800], [100, 1200], [100, 1400],
            [150, 1600], [150, 1800], [200, 2000], [150, 1800],
            [100, 1500], [100, 1200], [200, 2500], [100, 1000],
            [100, 1200], [100, 1000], [100, 1400], [150, 1600],
            [150, 1800], [200, 2200], [200, 2500], [200, 2200],
            [150, 1800], [100, 1500], [250, 3000], [100, 1200],
        ];

        $pickMonth = function () use ($monthlyWeights, $totalWeight): int {
            $rand = rand(1, $totalWeight);
            $cumulative = 0;

            foreach ($monthlyWeights as $i => $weight) {
                $cumulative += $weight;

                if ($rand <= $cumulative) {
                    return $i;
                }
            }

            return count($monthlyWeights) - 1;
        };

        // Weighted order item counts for varied Order Size Distribution chart
        $pickOrderItemCount = fn (): int => fake()->randomElement([
            // ~30% have 1 item, ~25% have 2, ~20% have 3, ~12% have 4, ~8% have 5, ~5% have 6-8
            ...array_fill(0, 6, 1),
            ...array_fill(0, 5, 2),
            ...array_fill(0, 4, 3),
            ...array_fill(0, 2, 4),
            ...array_fill(0, 2, 5),
            1 => 6, 7, 8,
        ]);

        $orders = $this->withProgressBar($vary(1000), fn () => Order::factory(1)
            ->sequence(function () use ($customers, $pickMonth, $monthlyPriceRanges) {
                $monthIndex = $pickMonth();
                $monthsAgo = 23 - $monthIndex;
                $start = now()->subMonths($monthsAgo)->startOfMonth();
                $end = min(now(), now()->subMonths($monthsAgo)->endOfMonth());
                [$minPrice, $maxPrice] = $monthlyPriceRanges[$monthIndex];

                return [
                    // ~15% of orders have no customer (guest checkout)
                    'customer_id' => fake()->boolean(85) ? $customers->random(1)->first()->id : null,
                    'created_at' => fake()->dateTimeBetween($start, $end),
                    'total_price' => fake()->randomFloat(2, $minPrice, $maxPrice),
                ];
            })
            ->has(Payment::factory()->count(rand(1, 3)))
            ->has(
                OrderItem::factory()->count($pickOrderItemCount())
                    ->state(fn (array $attributes, Order $order) => ['product_id' => $products->random(1)->first()->id]),
                'orderItems'
            )
            ->create());

        foreach ($orders->random(rand(5, 8)) as $order) {
            $customerName = $order->customer->name ?? 'A guest customer';

            Notification::make()
                ->title('New order')
                ->icon('heroicon-o-shopping-bag')
                ->body("{$customerName} ordered {$order->orderItems->count()} products.")
                ->actions([
                    Action::make('View')
                        ->url(OrderResource::getUrl('edit', ['record' => $order])),
                ])
                ->sendToDatabase($user->first());
        }
        $this->command->info('Orders created.');
    }

    protected function withProgressBar(int $amount, Closure $createCollectionOfOne): Collection
    {
        $progressBar = new ProgressBar($this->command->getOutput(), $amount);

        $progressBar->start();

        $items = new Collection;

        foreach (range(1, $amount) as $i) {
            $items->push(...$createCollectionOfOne());
            $progressBar->advance();
        }

        $progressBar->finish();

        $this->command->getOutput()->writeln('');

        return $items;
    }
}
