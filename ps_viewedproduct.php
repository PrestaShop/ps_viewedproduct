<?php
/**
* 2007-2018 PrestaShop.
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2016 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;
use PrestaShop\PrestaShop\Core\Module\WidgetInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_Viewedproduct extends Module implements WidgetInterface
{
    private $templateFile;
    private $currentProductId;

    public function __construct()
    {
        $this->name = 'ps_viewedproduct';
        $this->author = 'PrestaShop';
        $this->version = '1.2.4';
        $this->tab = 'front_office_features';
        $this->need_instance = 0;

        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        ];

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Viewed products block', [], 'Modules.Viewedproduct.Admin');
        $this->description = $this->trans(
            'Display a kind of showcase on your product pages with recently viewed products.',
            [],
            'Modules.Viewedproduct.Admin'
        );

        $this->templateFile = 'module:ps_viewedproduct/views/templates/hook/ps_viewedproduct.tpl';
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateValue('PRODUCTS_VIEWED_NBR', 8)
            && $this->registerHook('displayFooterProduct')
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('actionObjectProductDeleteAfter')
            && $this->registerHook('actionObjectProductUpdateAfter')
        ;
    }

    public function hookActionObjectProductDeleteAfter($params)
    {
        $this->_clearCache($this->templateFile);
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        $this->_clearCache($this->templateFile);
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitBlockViewed')) {
            if (!($productNbr = Tools::getValue('PRODUCTS_VIEWED_NBR')) || empty($productNbr)) {
                $output .= $this->displayError($this->trans(
                    'You must fill in the \'Products displayed\' field.',
                    [],
                    'Modules.Viewedproduct.Admin'
                ));
            } elseif (0 === (int) ($productNbr)) {
                $output .= $this->displayError($this->trans('Invalid number.', [], 'Modules.Viewedproduct.Admin'));
            } else {
                Configuration::updateValue('PRODUCTS_VIEWED_NBR', (int) $productNbr);

                $this->_clearCache($this->templateFile);

                $output .= $this->displayConfirmation($this->trans(
                    'The settings have been updated.',
                    [],
                    'Admin.Notifications.Success'
                ));
            }
        }

        return $output . $this->renderForm();
    }

    public function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Admin.Global'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Products to display', [], 'Modules.Viewedproduct.Admin'),
                        'name' => 'PRODUCTS_VIEWED_NBR',
                        'class' => 'fixed-width-xs',
                        'desc' => $this->trans(
                            'Define the number of products displayed in this block.',
                            [],
                            'Modules.Viewedproduct.Admin'
                        ),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];

        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->default_form_language = $lang->id;
        $configFormLang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->allow_employee_form_lang = $configFormLang ? $configFormLang : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitBlockViewed';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) .
            '&configure=' . $this->name .
            '&tab_module=' . $this->tab .
            '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$fields_form]);
    }

    public function getConfigFieldsValues()
    {
        return [
            'PRODUCTS_VIEWED_NBR' => Tools::getValue('PRODUCTS_VIEWED_NBR', Configuration::get('PRODUCTS_VIEWED_NBR')),
        ];
    }

    public function getCacheId($name = null)
    {
        $key = implode('|', $this->getViewedProductIds());

        return parent::getCacheId('ps_viewedproduct|' . $key);
    }

    public function renderWidget($hookName = null, array $configuration = [])
    {
        if (isset($configuration['product']['id_product'])) {
            $this->currentProductId = $configuration['product']['id_product'];
        }

        if ('displayProductAdditionalInfo' === $hookName) {
            $this->addViewedProduct($this->currentProductId);

            return;
        }

        if (!isset($this->context->cookie->viewed) || empty($this->context->cookie->viewed)) {
            return;
        }

        if (!$this->isCached($this->templateFile, $this->getCacheId())) {
            $variables = $this->getWidgetVariables($hookName, $configuration);

            if (empty($variables)) {
                return false;
            }

            $this->smarty->assign($variables);
        }

        return $this->fetch($this->templateFile, $this->getCacheId());
    }

    public function getWidgetVariables($hookName = null, array $configuration = [])
    {
        if (isset($configuration['product']['id_product'])) {
            $this->currentProductId = $configuration['product']['id_product'];
        }

        $products = $this->getViewedProducts();

        if (!empty($products)) {
            return [
                'products' => $products,
            ];
        }

        return false;
    }

    protected function addViewedProduct($idProduct)
    {
        $arr = [];

        if (isset($this->context->cookie->viewed)) {
            $arr = explode(',', $this->context->cookie->viewed);
        }

        if (!in_array($idProduct, $arr)) {
            $arr[] = $idProduct;
            $arr = array_reverse(array_slice(array_reverse($arr), 0, ((int) Configuration::get('PRODUCTS_VIEWED_NBR') + 1)));

            $this->context->cookie->viewed = trim(implode(',', $arr), ',');
        }
    }

    protected function getViewedProductIds()
    {
        $viewedProductsIds = array_reverse(explode(',', $this->context->cookie->viewed));
        if (null !== $this->currentProductId && in_array($this->currentProductId, $viewedProductsIds)) {
            $viewedProductsIds = array_diff($viewedProductsIds, [$this->currentProductId]);
        }

        $existingProducts = $this->getExistingProductsIds();
        $viewedProductsIds = array_filter($viewedProductsIds, function ($entry) use ($existingProducts) {
            return in_array($entry, $existingProducts);
        });

        return array_slice($viewedProductsIds, 0, (int) (Configuration::get('PRODUCTS_VIEWED_NBR')));
    }

    protected function getViewedProducts()
    {
        $productIds = $this->getViewedProductIds();

        if (!empty($productIds)) {
            $assembler = new ProductAssembler($this->context);

            $presenterFactory = new ProductPresenterFactory($this->context);
            $presentationSettings = $presenterFactory->getPresentationSettings();
            if (version_compare(_PS_VERSION_, '1.7.5', '>=')) {
                $presenter = new \PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter(
                    new ImageRetriever(
                        $this->context->link
                    ),
                    $this->context->link,
                    new PriceFormatter(),
                    new ProductColorsRetriever(),
                    $this->context->getTranslator()
                );
            } else {
                $presenter = new \PrestaShop\PrestaShop\Core\Product\ProductListingPresenter(
                    new ImageRetriever(
                        $this->context->link
                    ),
                    $this->context->link,
                    new PriceFormatter(),
                    new ProductColorsRetriever(),
                    $this->context->getTranslator()
                );
            }

            $products_for_template = [];

            if (is_array($productIds)) {
                foreach ($productIds as $productId) {
                    if ($this->currentProductId !== $productId) {
                        $products_for_template[] = $presenter->present(
                            $presentationSettings,
                            $assembler->assembleProduct(['id_product' => $productId]),
                            $this->context->language
                        );
                    }
                }
            }

            return $products_for_template;
        }

        return false;
    }

    /**
     * @return array the list of active product ids
     */
    private function getExistingProductsIds()
    {
        $existingProductsQuery = Db::getInstance((bool) _PS_USE_SQL_SLAVE_)->executeS('
            SELECT p.id_product
            FROM ' . _DB_PREFIX_ . 'product p
            WHERE p.active = 1'
        );

        return array_map(function ($entry) {
            return $entry['id_product'];
        }, $existingProductsQuery);
    }
}
