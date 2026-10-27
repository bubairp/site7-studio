<?php

namespace site7\studio\tests\unit\services\design;

use Codeception\Test\Unit;
use site7\studio\services\design\DesignReachability;

class DesignReachabilityTest extends Unit
{
    protected \UnitTester $tester;

    public function testTemplateNameDropsTheExtension(): void
    {
        $this->assertSame('_layouts/detail/blog', DesignReachability::templateName('_layouts/detail/blog.twig'));
        $this->assertSame('_components/x', DesignReachability::templateName('/_components/x.html'));
        $this->assertSame('_components/x', DesignReachability::templateName('_components/x'));
    }

    public function testLiteralIncludesCount(): void
    {
        $name = '_layouts/detail/blog.twig';
        $this->assertTrue(DesignReachability::references('{% include "_layouts/detail/blog" with { element: blog } %}', $name));
        $this->assertTrue(DesignReachability::references("{% include '_layouts/detail/blog.twig' %}", $name));
        $this->assertTrue(DesignReachability::references('{%- embed "_layouts/detail/blog" -%}', $name));
        $this->assertTrue(DesignReachability::references('{% extends "_layouts/detail/blog" %}', $name));
        $this->assertTrue(DesignReachability::references('{{ include("_layouts/detail/blog") }}', $name));
        $this->assertTrue(DesignReachability::references('{% import "_layouts/detail/blog" as b %}', $name));
    }

    public function testOtherNamesAndComputedNamesDoNot(): void
    {
        $name = '_layouts/detail/blog.twig';
        $this->assertFalse(DesignReachability::references('{% include "_layouts/detail/blog-old" %}', $name), 'a longer name');
        $this->assertFalse(DesignReachability::references('{% include "_layouts/detail/" ~ kind %}', $name), 'computed');
        $this->assertFalse(DesignReachability::references('<p>_layouts/detail/blog</p>', $name), 'just text');
        $this->assertFalse(DesignReachability::references('{% include "_layouts/detail/blog\' %}', $name), 'mismatched quotes');
    }

    public function testAnOverrideIsUnreachableWhenNoIncluderStillIncludesIt(): void
    {
        $includedBy = [
            '_layouts/detail/blog.twig' => ['entries/channels/blog.twig'],
            '_components/listing-filter.twig' => ['_components/_blog/searchBlog.twig', '_components/_products/searchProduct.twig'],
        ];
        $site = [
            'entries/channels/blog.twig' => '{% block content %}<div class="article-page">old copy</div>{% endblock %}',
            '_components/_blog/searchBlog.twig' => '<form>old</form>',
            '_components/_products/searchProduct.twig' => '{% include "_components/listing-filter" with {} only %}',
        ];
        $unreachable = DesignReachability::unreachable($includedBy, fn(string $path) => $site[$path] ?? null);

        $this->assertSame(['_layouts/detail/blog.twig' => ['entries/channels/blog.twig']], $unreachable, 'one includer of the filter still includes it');
    }

    public function testAMissingIncluderCountsAsNotIncluding(): void
    {
        $unreachable = DesignReachability::unreachable(['_x.twig' => ['a.twig']], fn() => null);
        $this->assertSame(['_x.twig' => ['a.twig']], $unreachable);
        $this->assertSame([], DesignReachability::unreachable([], fn() => null), 'an old package without includedBy is not checked');
    }

    public function testIncludedByFindsLiteralIncludersOnly(): void
    {
        $dir = sys_get_temp_dir() . '/s7-reach-' . uniqid();
        mkdir("{$dir}/entries/channels", 0777, true);
        mkdir("{$dir}/_includes", 0777, true);
        mkdir("{$dir}/_layouts/detail", 0777, true);
        file_put_contents("{$dir}/entries/channels/blog.twig", '{% include "_layouts/detail/blog" with { element: blog } %}');
        file_put_contents("{$dir}/_includes/matrix-container.twig", '{% include "_blocks/" ~ handle %}');
        file_put_contents("{$dir}/_layouts/detail/blog.twig", '{% include "_layouts/detail/blog" %}{# itself #}');

        $includedBy = DesignReachability::includedBy($dir, ['_layouts/detail/blog.twig', '_blocks/hero.twig']);

        $this->assertSame(['_layouts/detail/blog.twig' => ['entries/channels/blog.twig']], $includedBy, 'a dynamically included block has no includer, and a file never includes itself');
        array_map('unlink', [...glob("{$dir}/*/*/*.twig"), ...glob("{$dir}/*/*.twig")]);
    }

    public function testIncludedByReadsTheSitesCopyBeforeTheThemes(): void
    {
        $dir = sys_get_temp_dir() . '/s7-reach-layers-' . uniqid();
        mkdir("{$dir}/site/entries/channels", 0777, true);
        mkdir("{$dir}/theme/entries/channels", 0777, true);
        file_put_contents("{$dir}/theme/entries/channels/blog.twig", '{% include "_layouts/detail/blog" %}');
        file_put_contents("{$dir}/theme/entries/channels/news.twig", '{% include "_layouts/detail/blog" %}');
        file_put_contents("{$dir}/site/entries/channels/news.twig", '{# this site\'s own news page, no blog layout #}');

        $includedBy = DesignReachability::includedBy(["{$dir}/site", "{$dir}/theme"], ['_layouts/detail/blog.twig']);

        $this->assertSame(['_layouts/detail/blog.twig' => ['entries/channels/blog.twig']], $includedBy, 'the theme file counts, the site\'s own copy hides the theme\'s');
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testLiteralReferencesSkipComputedNames(): void
    {
        $source = '{% embed "_components/page-style" with { owner: entry } %}{% block body %}'
            . '{% import "_macros/images" as images %}{% include \'_includes/matrix-container.twig\' %}'
            . '{% include "_blocks/" ~ handle %}{% include "_components/_universal-card/_variants/" ~ file ~ ".twig" %}'
            . '{% from _self import x %}{% endblock %}{% endembed %}';
        $this->assertSame(['_components/page-style', '_includes/matrix-container', '_macros/images'], DesignReachability::literalReferences($source));
    }

    public function testATemplateTheSiteLacksIsMissing(): void
    {
        $design = [
            '_layouts/detail/blog.twig' => '{% embed "_components/page-style" %}{% endembed %}{% include "_includes/matrix-container" %}',
            '_components/listing-filter.twig' => '<form></form>',
        ];
        $siteHas = ['_includes/matrix-container'];
        $missing = DesignReachability::missing($design, fn(string $name) => in_array($name, $siteHas, true));

        $this->assertSame(['_components/page-style' => ['_layouts/detail/blog.twig']], $missing);
        $this->assertSame([], DesignReachability::missing($design, fn() => true), 'a site with a new enough Theme');
        $line = DesignReachability::missingMessages('Harbor', $missing)[0];
        $this->assertStringContainsString("Harbor needs _components/page-style, which this site doesn't have", $line);
        $this->assertStringContainsString("can't be activated", $line);
    }

    public function testMessagesNameTheFiles(): void
    {
        $lines = DesignReachability::messages('Harbor', ['_layouts/detail/blog.twig' => ['entries/channels/blog.twig']]);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString("Harbor's _layouts/detail/blog.twig isn't used on this site: entries/channels/blog.twig doesn't include it", $lines[0]);
    }
}
