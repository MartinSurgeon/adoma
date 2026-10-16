<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- Nothing here comes from another website. The typeface, styles and scripts are all built into the app's own
             files, so the screens look and work the same on a local network with no internet. --}}
        <meta name="theme-color" content="#134e4a">

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia

        {{-- If the app's files cannot be loaded, say so in words instead of leaving a blank page. It uses plain styles
             on purpose: the stylesheet may be the very thing that failed to load. --}}
        <div id="load-problem" role="alert" hidden
             style="position:fixed;left:0;right:0;top:0;z-index:9999;padding:16px;background:#fffbeb;color:#78350f;border-bottom:2px solid #f59e0b;font:16px/1.5 system-ui,sans-serif">
            <strong>This page did not load.</strong>
            Refresh it. If it is still blank, some of the system's files could not be loaded.
            Check your connection, then tell whoever looks after the system.
            <a href="" style="display:inline-block;min-height:48px;line-height:48px;margin-left:8px;color:#78350f;font-weight:600;text-decoration:underline">Refresh</a>
        </div>
        <noscript>
            <p style="padding:16px;font:16px/1.5 system-ui,sans-serif">This system needs JavaScript. Turn it on in your browser, then refresh.</p>
        </noscript>
        <script>
            (function () {
                var show = function () { var box = document.getElementById('load-problem'); if (box) box.hidden = false; };

                // A page file that could not be fetched (Vite reports it), or nothing drawn after a generous wait.
                window.addEventListener('vite:preloadError', show);
                setTimeout(function () {
                    var app = document.getElementById('app');
                    if (app && !app.childElementCount) show();
                }, 10000);
            })();
        </script>
    </body>
</html>
