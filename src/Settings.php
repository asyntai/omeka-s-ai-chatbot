<?php
namespace Asyntai;

/**
 * Everything the module decides about a stored value.
 *
 * The methods are static and touch nothing outside themselves, so the test
 * suite runs them without Omeka S.
 */
class Settings
{
    /**
     * Shape of a widget identifier from the Asyntai dashboard.
     */
    const ID_PATTERN = '/^asyntai_[A-Za-z0-9]{6,64}$/';

    /**
     * Address of the widget script when the administrator changes nothing.
     */
    const DEFAULT_SCRIPT_URL = 'https://widget.asyntai.com/static/js/chat-widget.js';

    /**
     * Read a widget identifier out of a stored value.
     *
     * Accepts a bare identifier or the whole snippet from the Asyntai
     * dashboard, because administrators paste the snippet. Answers an empty
     * string when there is nothing usable, which keeps the chat switched off.
     *
     * @param mixed $raw The stored value
     * @return string
     */
    public static function readWidgetId($raw)
    {
        if (!is_string($raw)) {
            return '';
        }

        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        // A pasted snippet arrives HTML encoded from some browsers, so decode
        // first and then read the attribute.
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

        if (preg_match('/data-asyntai-id\s*=\s*["\']([^"\']+)["\']/', $value, $matches)) {
            $value = trim($matches[1]);
        }

        return preg_match(self::ID_PATTERN, $value) ? $value : '';
    }

    /**
     * Read the script address out of a stored value.
     *
     * Only an https address is accepted, so a bad value can never put a
     * javascript: address on every page. An empty field means the
     * administrator wants the Asyntai address.
     *
     * @param mixed $raw The stored value
     * @return string Script address, or an empty string when the value is bad
     */
    public static function readScriptUrl($raw)
    {
        if (!is_string($raw)) {
            return self::DEFAULT_SCRIPT_URL;
        }

        $value = trim($raw);
        if ($value === '') {
            return self::DEFAULT_SCRIPT_URL;
        }

        return preg_match('#^https://[^\s"\'<>]+$#i', $value) ? $value : '';
    }

    /**
     * Choose the widget identifier for one public site.
     *
     * A site with its own identifier uses it. A site without one uses the
     * identifier of the installation. A site that is switched off has no chat.
     *
     * @param mixed $mainId The identifier from the module configuration
     * @param mixed $siteId The identifier from the site settings
     * @param mixed $siteOff The switch from the site settings
     * @return string
     */
    public static function chooseWidgetId($mainId, $siteId, $siteOff)
    {
        if ($siteOff) {
            return '';
        }

        $id = self::readWidgetId($siteId);
        return $id !== '' ? $id : self::readWidgetId($mainId);
    }

    /**
     * Write the script that fetches the chat after the page has loaded.
     *
     * The chat is fetched after the load event, so the page draws first and
     * page speed does not change. Slashes stay escaped in the output, so a
     * value can never close the script element.
     *
     * @param string $widgetId
     * @param string $scriptUrl
     * @return string
     */
    public static function loaderScript($widgetId, $scriptUrl)
    {
        return '(function () {'
            . 'var widgetId = ' . json_encode($widgetId) . ';'
            . 'var scriptUrl = ' . json_encode($scriptUrl) . ';'
            . 'var load = function () {'
            . 'if (document.querySelector("script[data-asyntai-id]")) { return; }'
            . 'var script = document.createElement("script");'
            . 'script.src = scriptUrl;'
            . 'script.async = true;'
            . 'script.setAttribute("data-asyntai-id", widgetId);'
            . 'document.head.appendChild(script);'
            . '};'
            . 'if (document.readyState === "complete") { load(); }'
            . 'else { window.addEventListener("load", load); }'
            . '}());';
    }
}
