<?php

use App\Models\Restaurant;
use App\Models\User;

function socialRestaurant(string $sub = 'marcos'): Restaurant
{
    return Restaurant::create([
        'name' => "R-{$sub}",
        'subdomain' => $sub,
        'email' => "hello@{$sub}.test",
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

function socialAdmin(Restaurant $r, string $role = 'admin'): User
{
    $u = User::factory()->admin()->create();
    $u->restaurants()->attach($r->id, ['role' => $role]);

    return $u;
}

function socialUrl(Restaurant $r): string
{
    return "http://{$r->subdomain}.plateful.test/admin/site/social";
}

test('guest cannot update social links', function () {
    $r = socialRestaurant();

    $this->post(socialUrl($r), ['social_links' => ['instagram' => 'https://instagram.com/x']])
        ->assertRedirect();

    expect($r->fresh()->social_links)->toBeNull();
});

test('staff cannot update social links', function () {
    $r = socialRestaurant();
    $staff = socialAdmin($r, 'staff');

    $this->actingAs($staff)
        ->post(socialUrl($r), ['social_links' => ['instagram' => 'https://instagram.com/x']])
        ->assertForbidden();

    expect($r->fresh()->social_links)->toBeNull();
});

test('admin can save social links and unknown keys are dropped', function () {
    $r = socialRestaurant();
    $admin = socialAdmin($r);

    $this->actingAs($admin)
        ->post(socialUrl($r), [
            'social_links' => [
                'instagram' => 'https://instagram.com/marcos',
                'facebook' => 'https://facebook.com/marcos',
                'twitter' => '',
                'myspace' => 'https://myspace.com/marcos', // unknown — dropped
            ],
        ])
        ->assertRedirect();

    $links = $r->fresh()->social_links;
    expect($links)->toBe([
        'instagram' => 'https://instagram.com/marcos',
        'facebook' => 'https://facebook.com/marcos',
    ]);
});

test('non-url values are rejected', function () {
    $r = socialRestaurant();
    $admin = socialAdmin($r);

    $this->actingAs($admin)
        ->post(socialUrl($r), [
            'social_links' => ['instagram' => 'not a url'],
        ])
        ->assertSessionHasErrors('social_links.instagram');
});

test('empty submission clears all social links', function () {
    $r = socialRestaurant();
    $r->social_links = ['instagram' => 'https://instagram.com/x'];
    $r->save();
    $admin = socialAdmin($r);

    $this->actingAs($admin)
        ->post(socialUrl($r), ['social_links' => []])
        ->assertRedirect();

    expect($r->fresh()->social_links)->toBeNull();
});

test('storefront home includes social links in restaurant prop', function () {
    $r = socialRestaurant();
    $r->social_links = ['instagram' => 'https://instagram.com/marcos'];
    $r->save();

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->where('restaurant.socialLinks.instagram', 'https://instagram.com/marcos')
        );
});
