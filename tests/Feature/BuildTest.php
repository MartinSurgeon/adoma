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

// The app may run on a local network with no internet. Anything fetched from another website (a font, a stylesheet
// from a CDN) would then fail, and the screens would lose their look. Everything must come from the app itself.

it('loads nothing from other websites, so every screen looks the same without the internet', function () {
    $html = $this->get(route('login'))->assertOk()->getContent();

    preg_match_all('/<(?:link|script|img|iframe)\b[^>]*\b(?:href|src)="([^"]+)"/i', $html, $found);

    $own = parse_url(config('app.url'), PHP_URL_HOST);
    $elsewhere = collect($found[1])
        ->filter(fn (string $url) => ($host = parse_url($url, PHP_URL_HOST)) !== null && $host !== $own)
        ->values()->all();

    expect($elsewhere)->toBe([])
        ->and($html)->not->toContain('fonts.bunny.net')->not->toContain('fonts.googleapis.com');
});

it('bundles the typeface with the app\'s own files, and the styles point nowhere else', function () {
    $styles = glob(public_path('build/assets').'/app-*.css');

    if ($styles === [] || $styles === false) {
        $this->markTestSkipped('The styles are not built here. Run npm run build first.');
    }

    $css = file_get_contents($styles[0]);

    // Every url(...) is a file beside the stylesheet or data written inline, never an address on the internet.
    preg_match_all('/url\(\s*["\']?([^)"\']+)/i', $css, $urls);
    $outside = collect($urls[1])->reject(fn (string $url) => str_starts_with($url, './') || str_starts_with($url, 'data:'))->values()->all();

    expect($css)->toContain('Inter Variable')
        ->and($outside)->toBe([])
        ->and(glob(public_path('build/assets').'/inter-latin-*.woff2'))->not->toBe([]);
});
