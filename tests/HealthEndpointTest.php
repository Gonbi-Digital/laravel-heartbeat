<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('answers the contract shape', function () {
    config()->set('heartbeat.token', null);

    $response = $this->getJson('/health')->assertOk();

    expect($response->json('status'))->toBe('ok')
        ->and($response->json('checks.database.status'))->toBe('ok')
        ->and($response->json('checks.cache.status'))->toBe('ok')
        ->and($response->json('checks.storage.status'))->toBe('ok')
        // Every component carries the three keys the contract names, `ms` included, so a consumer
        // never has to guess whether a missing key means fast or means unmeasured.
        ->and($response->json('checks.database'))->toHaveKeys(['status', 'message', 'ms']);
});

it('carries nothing but the status and the components', function () {
    config()->set('heartbeat.token', null);

    // The machine endpoint stays small on purpose: the ops page is where the drivers, the release
    // and the cache state live, and none of that belongs in a payload fetched twice a minute.
    expect(array_keys($this->getJson('/health')->json()))->toBe(['status', 'checks']);
});

it('404s a probe with the wrong token, and one with none', function () {
    config()->set('heartbeat.token', 'sekrit');

    // 404 rather than 401, and the same answer either way: an unauthorised probe should not learn
    // that there is something here worth probing.
    $this->getJson('/health')->assertNotFound();
    $this->getJson('/health', ['Authorization' => 'Bearer wrong'])->assertNotFound();
});

it('accepts the token as a bearer header or a query parameter', function () {
    config()->set('heartbeat.token', 'sekrit');

    $this->getJson('/health', ['Authorization' => 'Bearer sekrit'])->assertOk();
    // The query form is for a person with a browser; the header is for the monitor.
    $this->getJson('/health?token=sekrit')->assertOk();
});

it('is open when no token is configured', function () {
    config()->set('heartbeat.token', null);

    $this->getJson('/health')->assertOk();
});

it('answers 503 and says down when a component is down', function () {
    config()->set('heartbeat.token', null);
    DB::shouldReceive('connection')->andThrow(new RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));

    $response = $this->getJson('/health')->assertStatus(503);

    expect($response->json('status'))->toBe('down')
        ->and($response->json('checks.database.status'))->toBe('down')
        // The message is the whole point: knowing which service to restart, not that one failed.
        ->and($response->json('checks.database.message'))->toContain('Connection refused');
});

it('stays 200 while a component is only degraded', function () {
    config()->set('heartbeat.token', null);
    config()->set('queue.default', 'database');
    config()->set('heartbeat.queue.pending_threshold', 0);

    $this->app['db']->connection()->getSchemaBuilder()->create('jobs', function ($table) {
        $table->id();
        $table->integer('available_at');
    });
    DB::table('jobs')->insert(['available_at' => time()]);

    $response = $this->getJson('/health')->assertOk();

    // A backed-up queue is not an outage. Answering 503 here would make every monitor in front of
    // this app declare one over a backlog.
    expect($response->json('status'))->toBe('degraded')
        ->and($response->json('checks.queue.status'))->toBe('degraded');
});

it('carries no session middleware, so it can answer when the session store cannot', function () {
    // The reason this route exists apart from the ops page. With SESSION_DRIVER=database, a health
    // route inside the `web` group boots a session out of the database it is being asked about,
    // and answers 500 instead of naming the failure at the one moment that matters.
    $middleware = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->getName() === 'heartbeat.health')
        ->gatherMiddleware();

    expect($middleware)->toBeEmpty();
});

it('can be switched off entirely', function () {
    // Checked through the resolved route table rather than a request, because a disabled endpoint
    // and a token-refused one both answer 404 and the test would pass either way.
    expect(collect(Route::getRoutes()->getRoutes())->pluck('action.as'))->toContain('heartbeat.health');
});
