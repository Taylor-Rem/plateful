<?php

use App\Models\Order;
use App\Models\Restaurant;

test('order number prefix is uppercase first 3 letters of subdomain', function () {
    $r = new Restaurant(['subdomain' => 'marcos']);

    $number = Order::generateNumber($r);

    expect($number)->toMatch('/^MAR-[A-Z0-9]{5}$/');
});

test('order number pads short subdomain with X', function () {
    $r = new Restaurant(['subdomain' => 'co']);

    $number = Order::generateNumber($r);

    expect($number)->toMatch('/^COX-[A-Z0-9]{5}$/');
});

test('single-letter subdomain gets padded with two X', function () {
    $r = new Restaurant(['subdomain' => 'a']);

    $number = Order::generateNumber($r);

    expect($number)->toMatch('/^AXX-[A-Z0-9]{5}$/');
});
