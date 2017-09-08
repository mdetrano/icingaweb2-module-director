<?php

namespace Icinga\Module\Director\Web\Widget;

use Icinga\Module\Director\IcingaConfig\IcingaConfigFile;
use ipl\Html\Html;
use ipl\Html\HtmlString;
use ipl\Html\Link;
use ipl\Html\Util;
use ipl\Translation\TranslationHelper;

class ShowConfigFile extends Html
{
    use TranslationHelper;

    protected $file;

    protected $highlight;

    protected $highlightSeverity;

    public function __construct(
        IcingaConfigFile $file,
        $highlight = null,
        $highlightSeverity = null
    ) {
        $this->file = $file;
        $this->highlight         = $highlight;
        $this->highlightSeverity = $highlightSeverity;
        $this->prepareContent();
    }

    protected function prepareContent()
    {
        $source = $this->linkObjects(Util::escapeForHtml($this->file->getContent()));
        if ($this->highlight) {
            $source = $this->highlight(
                $source,
                $this->highlight,
                $this->highlightSeverity
            );
        }

        $this->add(Html::pre(
            ['class' => 'generated-config'],
            new HtmlString($source)
        ));
    }

    protected function linkObject($match)
    {
        if ($match[2] === 'Service') {
            return $match[0];
        }
        if ($match[2] === 'CheckCommand') {
            $match[2] = 'command';
        }

        $name = $this->decode($match[3]);
        return sprintf(
            '%s %s &quot;%s&quot; {',
            $match[1],
            $match[2],
            Link::create(
                $name,
                'director/' . $match[2],
                ['name' => $name],
                ['data-base-target' => '_next']
            )
        );
    }

    protected function decode($str)
    {
        return htmlspecialchars_decode($str, ENT_COMPAT | ENT_SUBSTITUTE | ENT_HTML5);
    }

    protected function linkObjects($config)
    {
        $pattern = '/^(object|template)\s([A-Z][A-Za-z]*?)\s&quot;(.+?)&quot;\s{/m';

        return preg_replace_callback(
            $pattern,
            [$this, 'linkObject'],
            $config
        );
    }

    protected function highlight($what, $line, $severity)
    {
        $lines = explode("\n", $what);
        $lines[$line - 1] = '<span class="highlight ' . $severity . '">' . $lines[$line - 1] . '</span>';
        return implode("\n", $lines);
    }
}
