<?php

namespace LeKoala\Mailgun;

use Pelago\Emogrifier;
use Pelago\Emogrifier\CssInliner;
use Pelago\Emogrifier\HtmlProcessor\HtmlPruner;
use Pelago\Emogrifier\HtmlProcessor\CssToAttributeConverter;

class EmailUtils
{

    /**
     * Inline styles using Pelago Emogrifier
     *
     * This is much better than the functionnality provided by Mailgun anyway
     *
     * @param string $html
     * @return string
     */
    public static function inline_styles($html)
    {
        $cssInliner = CssInliner::fromHtml($html)->inlineCss('');
        $domDocument = $cssInliner->getDomDocument();

        // potentially, we could also remove classes
        // HtmlPruner::fromDomDocument($domDocument)->removeElementsWithDisplayNone()
        //     ->removeRedundantClassesAfterCssInlined($cssInliner);

        // disableInvisibleNodeRemoval
        $doc =   HtmlPruner::fromDomDocument($domDocument);
        if (method_exists($doc, 'removeInvisibleNodes')) {
            $doc->removeInvisibleNodes();
        } else {
            $doc->removeElementsWithDisplayNone();
        }

        // enableCssToHtmlMapping
        $html = CssToAttributeConverter::fromDomDocument($domDocument)
            ->convertCssToVisualAttributes()->render();

        return $html;
    }


    /**
     * Convert an html email to a text email while keeping formatting and links
     *
     * @param string $content
     * @return string
     */
    public static function convert_html_to_text($content)
    {
        // Prevent styles to be included
        $content = preg_replace('/<style.*>([\s\S]*)<\/style>/i', '', $content);
        // Convert html entities to strip them later on
        $content = html_entity_decode($content);
        // Bold
        $content = str_ireplace(['<strong>', '</strong>', '<b>', '</b>'], "*", $content);
        // Replace links to keep them accessible
        $content = preg_replace('/<a[\s\S]href="(.*?)"[\s\S]*?>(.*?)<\/a>/i', '$2 ($1)', $content);
        // Replace new lines
        $content = str_replace(['<br>', '<br/>', '<br />'], "\r\n", $content);
        // Remove html tags
        $content = strip_tags($content);
        // Avoid lots of spaces
        $content = preg_replace('/^[\s][\s]+(\S)/m', "\n$1", $content);
        // Trim content so that it's nice
        $content = trim($content);
        return $content;
    }

    /**
     * Match all words and whitespace, will be terminated by '<'
     *
     * Note: use /u to support utf8 strings
     *
     * @param string $rfc_email_string
     * @return string
     */
    public static function get_displayname_from_rfc_email($rfc_email_string)
    {
        $name = preg_match('/[\w\s\-\.]+/u', $rfc_email_string, $matches);
        $matches[0] = trim($matches[0]);
        return $matches[0];
    }

    /**
     * Extract parts between brackets
     *
     * @param string $rfc_email_string
     * @return string
     */
    public static function get_email_from_rfc_email($rfc_email_string)
    {
        if (strpos($rfc_email_string, '<') === false) {
            return $rfc_email_string;
        }
        $mailAddress = preg_match('/(?:<)(.+)(?:>)$/', $rfc_email_string, $matches);
        if (empty($matches)) {
            return $rfc_email_string;
        }
        return $matches[1];
    }
}
