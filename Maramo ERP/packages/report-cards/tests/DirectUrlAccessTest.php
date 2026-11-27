<?php

namespace Classivo\ReportCards\Tests;

use Filament\Facades\Filament;

/**
 * Deny by default on direct URLs: a signed-in panel user with no permissions gets
 * 403 on every resource index / create URL and every page this package registers.
 */
class DirectUrlAccessTest extends UiTestCase
{
    /** @return list<string> */
    private function urls(): array
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        $urls = [];

        foreach ($panel->getResources() as $resource) {
            foreach (['index', 'create'] as $page) {
                if (array_key_exists($page, $resource::getPages())) {
                    $urls[] = $resource::getUrl($page);
                }
            }
        }

        foreach ($panel->getPages() as $page) {
            $urls[] = $page::getUrl();
        }

        return $urls;
    }

    public function test_every_panel_url_is_forbidden_without_permissions(): void
    {
        $this->actAs([]);
        $urls = $this->urls();
        $this->assertNotEmpty($urls);

        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
    }
}
