<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => $email,
            'password' => 'password123',
            'role' => $role,
        ]);
    }

    private function makeProduct(int $stock = 8): Product
    {
        $category = Category::firstOrCreate(['slug' => 'elektronik'], ['name' => 'Elektronik']);

        return Product::create([
            'category_id' => $category->id,
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Mouse Wireless',
            'slug' => 'mouse-'.uniqid(),
            'description' => 'Produk contoh',
            'price' => 150000,
            'stock' => $stock,
            'is_active' => true,
        ]);
    }

    private function makeShipping(): ShippingService
    {
        return ShippingService::create([
            'courier' => 'JNE',
            'service' => 'REG',
            'eta' => '2-3 hari',
            'cost' => 20000,
            'is_active' => true,
        ]);
    }

    private function payload(int $shippingId, int $productId, int $qty): array
    {
        return [
            'recipient_name' => 'Pelanggan',
            'phone' => '081234567890',
            'address' => 'Purwokerto',
            'shipping_service_id' => $shippingId,
            'items' => [['product_id' => $productId, 'quantity' => $qty]],
        ];
    }

    /** Customer checkout 2 item. Mengembalikan [customer, product, orderId]. */
    private function checkoutAsCustomer(string $email = 'customer@example.com'): array
    {
        $customer = $this->makeUser('customer', $email);
        $product = $this->makeProduct(8);
        $shipping = $this->makeShipping();

        Sanctum::actingAs($customer, ['customer']);

        $res = $this->postJson('/api/v1/checkout', $this->payload($shipping->id, $product->id, 2));
        $res->assertCreated();

        return [$customer, $product, $res->json('data.id')];
    }

    // ---------- Endpoint baru: customer cancel order ----------

    public function test_customer_can_cancel_pending_order_and_stock_is_restored(): void
    {
        [, $product, $orderId] = $this->checkoutAsCustomer();
        $this->assertSame(6, $product->fresh()->stock);

        $this->patchJson("/api/v1/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(8, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'IN',
            'quantity' => 2,
        ]);
    }

    public function test_customer_cannot_cancel_non_pending_order(): void
    {
        [, , $orderId] = $this->checkoutAsCustomer();
        Order::whereKey($orderId)->update(['status' => 'confirmed']);

        $this->patchJson("/api/v1/orders/{$orderId}/cancel")->assertStatus(409);
    }

    public function test_customer_cannot_cancel_other_customers_order(): void
    {
        [, , $orderId] = $this->checkoutAsCustomer();

        $other = $this->makeUser('customer', 'lain@example.com');
        Sanctum::actingAs($other, ['customer']);

        $this->patchJson("/api/v1/orders/{$orderId}/cancel")->assertNotFound();
    }

    // ---------- Negative test endpoint yang sudah ada ----------

    public function test_checkout_without_token_returns_401(): void
    {
        $this->postJson('/api/v1/checkout', [])->assertUnauthorized();
    }

    public function test_admin_token_cannot_checkout_returns_403(): void
    {
        $admin = $this->makeUser('admin', 'admin@example.com');
        $product = $this->makeProduct();
        $shipping = $this->makeShipping();

        Sanctum::actingAs($admin, ['admin']);

        $this->postJson('/api/v1/checkout', $this->payload($shipping->id, $product->id, 1))
            ->assertForbidden();
    }

    public function test_checkout_with_insufficient_stock_returns_422(): void
    {
        $customer = $this->makeUser('customer', 'customer@example.com');
        $product = $this->makeProduct(8);
        $shipping = $this->makeShipping();

        Sanctum::actingAs($customer, ['customer']);

        $this->postJson('/api/v1/checkout', $this->payload($shipping->id, $product->id, 50))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_admin_cannot_confirm_order_twice_returns_409(): void
    {
        [, , $orderId] = $this->checkoutAsCustomer();

        $admin = $this->makeUser('admin', 'admin@example.com');
        Sanctum::actingAs($admin, ['admin']);

        $this->patchJson("/api/v1/admin/orders/{$orderId}/confirm")->assertOk();
        $this->patchJson("/api/v1/admin/orders/{$orderId}/confirm")->assertStatus(409);
    }
}