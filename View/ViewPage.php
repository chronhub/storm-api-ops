<?php

declare(strict_types=1);

namespace Storm\ApiOps\View;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

use function htmlspecialchars;
use function implode;
use function sprintf;

/**
 * The chrome every operator screen shares: the document, the style, the navigation, the refresh, and
 * the one function that makes a string safe to print.
 *
 * Escaping lives HERE and not on each screen, which is the reason this class exists at all. These
 * pages print stored payloads, stored type names and stored error text, the least trustworthy
 * strings in the system, and the veil upstream hides personal values without making anything safe
 * for HTML. A helper copied per screen is one screen away from being forgotten on the newest, and
 * that screen would look correct on every benign input.
 *
 * There is no template engine behind this and the absence is a decision: what an engine would buy is
 * author comfort, and its one safety argument, auto-escaping, is the function below, tested and
 * shared. A package does not gain a dependency for something it knows how to write.
 */
final readonly class ViewPage
{
    /**
     * Every screen, in the order an operator meets them, keyed by ROUTE NAME so the navigation is one
     * list to edit and the links survive whatever prefix an application mounts this surface under.
     */
    public const array SCREENS = [
        'storm_view_backlog' => 'backlog',
        'storm_view_outbox_failed' => 'dead-letters',
        'storm_view_projections' => 'projections',
        'storm_view_sagas' => 'sagas',
        'storm_view_saga' => 'saga detail',
        'storm_view_streams' => 'streams',
        'storm_view_correlations' => 'correlation trace',
    ];

    public function __construct(
        private ?UrlGeneratorInterface $urls = null,
    ) {}

    /**
     * Wrap a screen's body in the shared document.
     *
     * @param  int  $refreshSeconds  zero leaves the page still; a browser that reloads a screen
     *                               nobody is watching costs the store a query per tick
     */
    // @infection-ignore-all equivalent: the default's value below 1 is unobservable, the poll being
    // emitted on `> 0`, so zero and a negative are the same answer to a reader
    public function render(string $title, string $body, int $refreshSeconds = 0): string
    {
        $poll = $refreshSeconds > 0
            ? sprintf('<script>setTimeout(function(){location.reload();}, %d);</script>', $refreshSeconds * 1000)
            : '';

        $safeTitle = $this->text($title);
        $nav = $this->nav($title);

        return <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8"><title>Storm — {$safeTitle}</title>
            <style>
            body{font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;margin:1.5rem;color:#222}
            h1{font-size:1rem;margin:0 0 .25rem}
            nav{margin:0 0 1rem;display:flex;gap:.75rem;flex-wrap:wrap}
            nav a{color:#4a7fd4}
            nav strong{color:#222}
            form{margin:0 0 1rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap}
            table{border-collapse:collapse;width:100%}
            th,td{border-bottom:1px solid #ddd;padding:.3rem .5rem;text-align:left;vertical-align:top}
            th{background:#f6f6f6}
            th.n,td.n{text-align:right}
            td.n{color:#666}
            td.t{white-space:nowrap}
            pre{margin:0;white-space:pre-wrap;word-break:break-word;color:#333}
            .notice{background:#eef4ff;border-left:3px solid #4a7fd4;padding:.5rem .5rem .5rem 1.5rem;margin:0 0 1rem}
            .degraded{background:#fdecea;border-left:3px solid #c0392b;padding:.5rem .5rem .5rem 1.5rem;margin:0 0 1rem}
            .sum{color:#555;margin:0 0 .5rem}
            </style></head><body>
            <h1>Storm — {$safeTitle}</h1>
            {$nav}
            {$body}
            {$poll}
            </body></html>
            HTML;
    }

    /**
     * Make a stored string safe to print. Every value reaching the markup goes through this, without
     * exception: a screen that judges which of its values are trustworthy has already lost.
     */
    public function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The links are GENERATED, never assembled from the path that arrived.
     *
     * A screen sits at `/_storm/view/...` in its own metadata and at `/api/_storm/view/...` in an
     * application that mounts the surface under a prefix. Cutting the prefix out of the current path
     * would work on a direct hit and lie behind a proxy that rewrites it; the router already holds
     * the answer, request context included, so it is the one asked.
     */
    private function nav(string $current): string
    {
        if ($this->urls === null) {
            return '';
        }

        $links = [];

        foreach (self::SCREENS as $route => $label) {
            if ($label === $current) {
                // the screen you are on is named but not linked: a link to here reads as somewhere else
                $links[] = '<strong>'.$this->text($label).'</strong>';

                continue;
            }

            try {
                $links[] = sprintf('<a href="%s">%s</a>', $this->text($this->urls->generate($route)), $this->text($label));
            } catch (Throwable) {
                // a screen whose route the application did not import is not a broken page, it is one
                // fewer link: the surface is opt-in per resource and a consumer may take a subset
                $links[] = '<strong>'.$this->text($label).'</strong>';
            }
        }

        return sprintf('<nav>%s</nav>', implode('', $links));
    }
}
