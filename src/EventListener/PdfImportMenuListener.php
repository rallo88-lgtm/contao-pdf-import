<?php

namespace Rallo\ContaoPdfImport\EventListener;

use Contao\CoreBundle\Event\ContaoCoreEvents;
use Contao\CoreBundle\Event\MenuEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Registriert die BE-Section "RCT TOOLBOX" + die drei pdf-import-Sub-Items.
 *
 * Section-Sharing: andere Toolbox-Bundles (toc-generator, translation) duerfen
 * dieselbe Top-Level-Category "rct_toolbox" mit eigenen Sub-Items befuellen.
 * Der Listener prueft ob die Category schon im Tree existiert und legt sie nur
 * an wenn nicht — Race-frei, beliebige Listener-Reihenfolge zwischen Bundles.
 *
 * Icons aus src/Resources/public/icons/, werden via assets:install nach
 * public/bundles/contaopdfimport/icons/ deployed.
 */
#[AsEventListener(ContaoCoreEvents::BACKEND_MENU_BUILD, priority: -255)]
class PdfImportMenuListener
{
    private const CATEGORY_NAME = 'rct_toolbox';
    private const CATEGORY_LABEL = 'RCT TOOLBOX';
    private const ICON_BASE = 'bundles/contaopdfimport/icons';

    public function __construct(private readonly RequestStack $requestStack) {}

    public function __invoke(MenuEvent $event): void
    {
        $tree = $event->getTree();
        if ('mainMenu' !== $tree->getName()) {
            return;
        }

        $factory = $event->getFactory();
        $request = $this->requestStack->getCurrentRequest();
        $current = $request?->attributes->get('_route');

        // Section-Sharing: ggf. existierende Toolbox-Category wiederverwenden.
        $category = $tree->getChild(self::CATEGORY_NAME);
        if (null === $category) {
            // Trick: parallele Klasse "group-system" piggybackt das Standard-
            // Contao-Wrench-Icon-CSS (inkl. dark-mode + active-state).
            // Spart eigenes BE-CSS und Cache-Buster-Listener. Wenn wir spaeter
            // eigenes Icon wollen, kommt die individuelle Class group-rct_toolbox
            // mit eigener CSS-Regel dazu.
            $category = $factory->createItem(self::CATEGORY_NAME)
                ->setLabel(self::CATEGORY_LABEL)
                ->setUri('/contao?mtg=' . self::CATEGORY_NAME)
                ->setLinkAttribute('class', 'group-rct_toolbox group-system')
                ->setLinkAttribute('data-action', 'contao--toggle-navigation#toggle:prevent')
                ->setLinkAttribute('data-contao--toggle-navigation-category-param', self::CATEGORY_NAME)
                ->setLinkAttribute('aria-controls', self::CATEGORY_NAME)
                ->setLinkAttribute('aria-expanded', 'true')
                ->setLinkAttribute('data-turbo-prefetch', 'false')
                ->setLinkAttribute('data-contao--tooltips-target', 'tooltip')
                ->setLinkAttribute('title', 'Bereich schließen')
                ->setChildrenAttribute('id', self::CATEGORY_NAME);
            $tree->addChild($category);
        }

        $category->addChild('pdf_import', [
            'route'  => 'pdf_import',
            'label'  => 'PDF-Import',
            'extras' => ['icon' => self::ICON_BASE . '/file-import.svg', 'isSafe' => true],
        ])
        ->setLinkAttribute('class', 'navigation pdf_import')
        ->setCurrent($current === 'pdf_import');

        $category->addChild('pdf_import_inspector', [
            'route'  => 'pdf_import_inspector',
            'label'  => 'Textract-Inspektor',
            'extras' => ['icon' => self::ICON_BASE . '/eye.svg', 'isSafe' => true],
        ])
        ->setLinkAttribute('class', 'navigation pdf_import_inspector')
        ->setCurrent($current === 'pdf_import_inspector');

        $category->addChild('pdf_import_config', [
            'route'  => 'pdf_import_config',
            'label'  => 'Konfiguration',
            'extras' => ['icon' => self::ICON_BASE . '/settings.svg', 'isSafe' => true],
        ])
        ->setLinkAttribute('class', 'navigation pdf_import_config')
        ->setCurrent($current === 'pdf_import_config');
    }
}
