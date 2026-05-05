<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;

class Highlight extends Component
{
    public string $text;

    public string $term;

    public function __construct(?string $text = '', ?string $term = '')
    {
        $this->text = (string) $text;
        $this->term = (string) $term;
    }

    public function highlighted(): HtmlString
    {
        $term = trim($this->term);

        if ($term === '') {
            return new HtmlString(e($this->text));
        }

        $pattern = '/('.preg_quote($term, '/').')/iu';
        $parts = preg_split($pattern, $this->text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $html = '';
        foreach ($parts as $i => $part) {
            $html .= $i % 2 === 1
                ? '<mark class="rounded-sm bg-amber-200/80 px-0.5 text-zinc-900 dark:bg-amber-400/30 dark:text-amber-100">'.e($part).'</mark>'
                : e($part);
        }

        return new HtmlString($html);
    }

    public function render(): View
    {
        return view('components.ui.highlight');
    }
}
