<?php

test('central home page returns a successful response', function () {
    $response = $this->get(route('central.home'));

    $response->assertOk();
});
