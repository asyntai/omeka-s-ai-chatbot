<?php
namespace Asyntai;

use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Form\Form;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Form\SiteSettingsForm;
use Omeka\Module\AbstractModule;

/**
 * Puts the Asyntai chat on the public pages of Omeka S.
 *
 * The administrator pages never get the chat. Until an administrator saves a
 * widget identifier the module writes nothing, so an unfinished setup changes
 * no page.
 */
class Module extends AbstractModule
{
    const GLOBAL_KEYS = ['asyntai_widget_id', 'asyntai_guests_only', 'asyntai_script_url'];
    const SITE_KEYS = ['asyntai_site_widget_id', 'asyntai_site_off'];

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach('*', 'view.layout', [$this, 'addChat']);
        $sharedEventManager->attach(SiteSettingsForm::class, 'form.add_elements', [$this, 'addSiteElements']);
        $sharedEventManager->attach(SiteSettingsForm::class, 'form.add_input_filters', [$this, 'addSiteInputFilters']);
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        foreach (self::GLOBAL_KEYS as $key) {
            $settings->delete($key);
        }

        $serviceLocator->get('Omeka\Connection')->executeStatement(
            'DELETE FROM site_setting WHERE id IN (?, ?)',
            self::SITE_KEYS
        );
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');

        $form = new Form('asyntai');
        $form->add([
            'name' => 'asyntai_widget_id',
            'type' => 'Text',
            'options' => [
                'label' => 'Widget ID', // @translate
                'info' => 'Find it in your Asyntai dashboard. You can paste the whole snippet. An empty field switches the chat off.', // @translate
            ],
            'attributes' => [
                'id' => 'asyntai_widget_id',
                'placeholder' => 'asyntai_ab12cd34',
                'value' => $settings->get('asyntai_widget_id', ''),
            ],
        ]);
        $form->add([
            'name' => 'asyntai_guests_only',
            'type' => 'Checkbox',
            'options' => [
                'label' => 'Show the chat to guests only', // @translate
                'info' => 'Users who are signed in to Omeka S do not see the chat.', // @translate
            ],
            'attributes' => [
                'id' => 'asyntai_guests_only',
                'value' => $settings->get('asyntai_guests_only', '0'),
            ],
        ]);
        $form->add([
            'name' => 'asyntai_script_url',
            'type' => 'Text',
            'options' => [
                'label' => 'Widget script address', // @translate
                'info' => 'Change this only if Asyntai support asks you to.', // @translate
            ],
            'attributes' => [
                'id' => 'asyntai_script_url',
                'placeholder' => Settings::DEFAULT_SCRIPT_URL,
                'value' => $settings->get('asyntai_script_url', ''),
            ],
        ]);

        $guide = '<p><a href="https://asyntai.com/documentation/integrations/omeka/" target="_blank" rel="noopener">'
            . $renderer->escapeHtml($renderer->translate('Read the setup guide')) // @translate
            . '</a></p>';

        return $guide . $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $post = $controller->params()->fromPost();
        $rawId = trim((string) ($post['asyntai_widget_id'] ?? ''));
        $rawUrl = trim((string) ($post['asyntai_script_url'] ?? ''));

        $widgetId = Settings::readWidgetId($rawId);
        if ($rawId !== '' && $widgetId === '') {
            $controller->messenger()->addError('This is not an Asyntai widget ID.'); // @translate
            return false;
        }
        if (Settings::readScriptUrl($rawUrl) === '') {
            $controller->messenger()->addError('The widget script address must be an https address.'); // @translate
            return false;
        }

        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->set('asyntai_widget_id', $widgetId);
        $settings->set('asyntai_guests_only', empty($post['asyntai_guests_only']) ? '0' : '1');
        $settings->set('asyntai_script_url', $rawUrl);
        return true;
    }

    public function addSiteElements(Event $event)
    {
        $form = $event->getTarget();
        $siteSettings = $this->getServiceLocator()->get('Omeka\Settings\Site');

        $groups = $form->getOption('element_groups') ?: [];
        $groups['asyntai'] = 'Asyntai AI Assistant'; // @translate
        $form->setOption('element_groups', $groups);

        $form->add([
            'name' => 'asyntai_site_widget_id',
            'type' => 'Text',
            'options' => [
                'element_group' => 'asyntai',
                'label' => 'Widget ID for this site', // @translate
                'info' => 'Use a different chat on this site. An empty field uses the widget ID from the module settings.', // @translate
            ],
            'attributes' => [
                'id' => 'asyntai_site_widget_id',
                'placeholder' => 'asyntai_ab12cd34',
                'value' => $siteSettings->get('asyntai_site_widget_id', ''),
            ],
        ]);
        $form->add([
            'name' => 'asyntai_site_off',
            'type' => 'Checkbox',
            'options' => [
                'element_group' => 'asyntai',
                'label' => 'Do not show the chat on this site', // @translate
            ],
            'attributes' => [
                'id' => 'asyntai_site_off',
                'value' => $siteSettings->get('asyntai_site_off', '0'),
            ],
        ]);
    }

    public function addSiteInputFilters(Event $event)
    {
        $inputFilter = $event->getParam('inputFilter');
        foreach (self::SITE_KEYS as $key) {
            $inputFilter->add(['name' => $key, 'required' => false]);
        }
    }

    public function addChat(Event $event)
    {
        $services = $this->getServiceLocator();
        if (!$services->get('Omeka\Status')->isSiteRequest()) {
            return;
        }

        $settings = $services->get('Omeka\Settings');
        $siteSettings = $services->get('Omeka\Settings\Site');

        $widgetId = Settings::chooseWidgetId(
            $settings->get('asyntai_widget_id'),
            $siteSettings->get('asyntai_site_widget_id'),
            $siteSettings->get('asyntai_site_off')
        );
        if ($widgetId === '') {
            return;
        }

        $scriptUrl = Settings::readScriptUrl($settings->get('asyntai_script_url'));
        if ($scriptUrl === '') {
            return;
        }

        if ($settings->get('asyntai_guests_only') && $services->get('Omeka\AuthenticationService')->hasIdentity()) {
            return;
        }

        $event->getTarget()->headScript()->appendScript(Settings::loaderScript($widgetId, $scriptUrl));
    }
}
