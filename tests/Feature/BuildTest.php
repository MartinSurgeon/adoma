<?php

// A white screen once came from the build, not the code: every page is its own script file, and the build wrote
// their addresses as "/build/assets/...", from the top of the website. That is wrong whenever the app lives in a
// subfolder (http://localhost/adoma/public/...), so every page that was not already loaded failed with a 404 and
// the screen stayed blank. These checks keep that from coming back.

it('builds scripts that find their other files beside themselves, so the app works in any folder', function () {
    $files = glob(public_path('build/assets').'/*.js');

    if ($files === [] || $files === false) {
        $this->markTestSkipped('The scripts are not built here. Run npm run build first.');
    }

    $rootRelative = collect($files)->filter(function (string $file) {
        $code = file_get_contents($file);

        return str_contains($code, '"/build/assets/') || str_contains($code, "'/build/assets/") || str_contains($code, '`/build/assets/');
    })->map(fn ($f) => basename($f))->values()->all();

    expect($rootRelative)->toBe([]);
});

it('sets a relative base for the build, which is what makes the scripts find each other', function () {
    expect(file_get_contents(base_path('vite.config.js')))->toContain("base: command === 'build' ? './'");
});

it('says so in words when the app\'s files cannot load, instead of showing a blank page', function () {
    $this->get(route('login'))->assertOk()
        ->assertSee('id="load-problem"', false)
        ->assertSee('This page did not load.', false);
});
