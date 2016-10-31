<?php
/*
* 2007-2016 PrestaShop
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

use PrestaShop\PrestaShop\Core\Module\WidgetInterface;
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Core\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ps_ViewedProduct extends Module implements WidgetInterface
{
    const TEMPLATE_PATH =
        'module:ps_viewedproduct/views/template/hook/ps_viewedproduct.tpl';

    protected static $cache_product_keys = array();

    public function __construct()
    {
        $this->name = 'ps_viewedproduct';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans(
            'Viewed products block',
            array(),
            'Modules.Viewedproduct.Admin'
        );
        $this->description = $this->trans(
            'Adds a block displaying recently viewed products.',
            array(),
            'Modules.Viewedproduct.Admin'
        );
        $this->ps_versions_compliancy = array(
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_,
        );
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('displayLeftColumn')
            && $this->registerHook('displayRightColumn')
            && $this->registerHook('actionObjectProductDeleteAfter')
            && $this->registerHook('actionObjectProductUpdateAfter')
            && Configuration::updateValue('PRODUCTS_VIEWED_NBR', 2);
    }

    public function hookActionObjectProductDeleteAfter($params)
    {
        $this->_clearCache('*');
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        $this->_clearCache('*');
    }

    public function getContent()
    {
        $output = '';
        if (Tools::isSubmit('submitBlockViewed')) {
            if (!($productNbr = Tools::getValue('PRODUCTS_VIEWED_NBR'))
                || empty($productNbr)) {
                $output .= $this->displayError($this->trans(
                    'You must fill in the \'Products displayed\' field.',
                    array(),
                    'Modules.Viewedproduct.Admin')
                );
            } elseif (0 === (int)($productNbr)) {
                $output .= $this->displayError(
                    $this->trans(
                        'Invalid number.',
                        array(),
                        'Modules.Viewedproduct.Admin'
                    )
                );
            } else {
                Configuration::updateValue(
                    'PRODUCTS_VIEWED_NBR',
                    (int)$productNbr
                );
                $this->_clearCache('*');
                $output .= $this->displayConfirmation(
                    $this->trans(
                        'Settings updated.',
                        array(),
                        'Modules.Viewedproduct.Admin'
                    )
                );
            }
        }
        return $output . $this->renderForm();
    }

    public function _clearCache(
        $templateName,
        $cacheId = null,
        $compileId = null
    ) {
        return parent::_clearCache(self::TEMPLATE_PATH);
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans(
                        'Settings',
                        array(),
                        'Modules.Viewedproduct.Admin'
                    ),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->trans(
                            'Products to display',
                            array(),
                            'Modules.Viewedproduct.Admin'
                        ),
                        'name' => 'PRODUCTS_VIEWED_NBR',
                        'class' => 'fixed-width-xs',
                        'desc' => $this->trans(
                            'Define the number of products displayed in this ' .
                            'block.',
                            array(),
                            'Modules.Viewedproduct.Admin'
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans(
                        'Save',
                        array(),
                        'Modules.Viewedproduct.Admin'
                    ),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table =  $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang =
            Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ?
            Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') :
            0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitBlockViewed';
        $helper->currentIndex = $this->context->link->getAdminLink(
                'AdminModules',
                false
            ) .
            '&configure=' . $this->name .
            '&tab_module=' . $this->tab .
            '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'PRODUCTS_VIEWED_NBR' => Tools::getValue(
                'PRODUCTS_VIEWED_NBR',
                Configuration::get('PRODUCTS_VIEWED_NBR')
            ),
        );
    }

    public function addViewedProduct($idProduct)
    {
        $arr = array();

        if (isset($this->context->cookie->viewed)) {
            $arr = explode(',', $this->context->cookie->viewed);
        }

        if (!in_array($idProduct, $arr)) {
            $arr[] = $idProduct;
            $this->context->cookie->viewed = implode(',', $arr);
        }
    }

    public function getViewedProductIds()
    {
        if (!empty(self::$cache_product_keys)) {
            return self::$cache_product_keys;
        }

        return self::$cache_product_keys= array_slice(
            array_reverse(
                explode(
                    ',',
                    $this->context->cookie->viewed
                )
            ),
            0,
            Configuration::get('PRODUCTS_VIEWED_NBR')
        );
    }

    public function getViewedProducts()
    {
        $productsViewed = $this->getViewedProductIds();

        $defaultCover = Language::getIsoById(
                $this->context->language->id
            ) . '-default';

        $productIds = implode(',', array_map('intval', $productsViewed));
        $productsImages = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
        SELECT  MAX(image_shop.id_image) id_image,
                p.id_product,
                il.legend,
                product_shop.active,
                pl.name,
                pl.description_short,
                pl.link_rewrite,
                cl.link_rewrite AS category_rewrite
        FROM '._DB_PREFIX_.'product p
        '.Shop::addSqlAssociation('product', 'p').'
        LEFT JOIN '._DB_PREFIX_.'product_lang pl
        ON (pl.id_product = p.id_product' .
            Shop::addSqlRestrictionOnLang('pl').')
        LEFT JOIN '._DB_PREFIX_.'image i
        ON (i.id_product = p.id_product)'.
            Shop::addSqlAssociation('image', 'i', false, 'image_shop.cover=1').'
        LEFT JOIN '._DB_PREFIX_.'image_lang il
        ON (il.id_image = image_shop.id_image
        AND il.id_lang = '.(int)($this->context->language->id).')
        LEFT JOIN '._DB_PREFIX_.'category_lang cl
        ON (cl.id_category = product_shop.id_category_default' .
            Shop::addSqlRestrictionOnLang('cl').')
        WHERE p.id_product IN ('.$productIds.')
        AND pl.id_lang = '.(int)($this->context->language->id).'
        AND cl.id_lang = '.(int)($this->context->language->id).'
        GROUP BY product_shop.id_product'
        );

        $productsImagesArray = array();
        foreach ($productsImages as $pi) {
            $productsImagesArray[$pi['id_product']] = $pi;
        }

        $productsViewedObj = array();
        foreach ($productsViewed as $productViewed) {
            $obj = (object)'Product';
            if (!isset($productsImagesArray[$productViewed])
                || (!$obj->active =
                    $productsImagesArray[$productViewed]['active'])) {
                continue;
            } else {
                $obj->id =
                    (int)($productsImagesArray[$productViewed]['id_product']);
                $obj->id_image =
                    (int)$productsImagesArray[$productViewed]['id_image'];
                $obj->id_product = $obj->id;
                $obj->cover =
                    (int)($productsImagesArray[$productViewed]['id_product']) .
                    '-'.(int)($productsImagesArray[$productViewed]['id_image']);
                $obj->legend = $productsImagesArray[$productViewed]['legend'];
                $obj->name = $productsImagesArray[$productViewed]['name'];
                $obj->description_short =
                    $productsImagesArray[$productViewed]['description_short'];
                $obj->link_rewrite =
                    $productsImagesArray[$productViewed]['link_rewrite'];
                $obj->category_rewrite =
                    $productsImagesArray[$productViewed]['category_rewrite'];
                $obj->product_link = $this->context->link->getProductLink(
                    $obj->id,
                    $obj->link_rewrite,
                    $obj->category_rewrite
                );

                if (!isset($obj->cover)
                    || !$productsImagesArray[$productViewed]['id_image']) {
                    $obj->cover = $defaultCover;
                    $obj->legend = '';
                }
                $productsViewedObj[] = $obj;
            }
        }

        if (!count($productsViewedObj)) {
            return;
        }

        $assembler = new ProductAssembler($this->context);

        $presenterFactory = new ProductPresenterFactory($this->context);
        $presentationSettings = $presenterFactory->getPresentationSettings();
        $presenter = new ProductListingPresenter(
            new ImageRetriever(
                $this->context->link
            ),
            $this->context->link,
            new PriceFormatter(),
            new ProductColorsRetriever(),
            $this->context->getTranslator()
        );

        $products_for_template = array();

        if (is_array($productsViewedObj)) {
            foreach ($productsViewedObj as $rawProduct) {
                $products_for_template[] = $presenter->present(
                    $presentationSettings,
                    $assembler->assembleProduct((array)$rawProduct),
                    $this->context->language
                );
            }
        }

        return $products_for_template;
    }

    public function getCacheId($name = null)
    {
        $key = implode(
            '|',
            $this->getViewedProductIds()
        );
        return parent::getCacheId('ps_viewedproduct|' . $key);
    }

    public function trimViewedProducts()
    {
        $arr = $this->getViewedProductIds();

        $this->context->cookie->viewed = implode(
            ',',
            array_reverse($arr)
        );
    }

    public function renderWidget($hookName, array $configuration)
    {
        if ('displayProductAdditionalInfo' === $hookName) {
            $this->addViewedProduct($configuration['product']['id_product']);
            $this->trimViewedProducts();
            return;
        }

        if (!isset($this->context->cookie->viewed)
            || empty($this->context->cookie->viewed)) {
            return;
        }

        $isCached = $this->isCached(
            self::TEMPLATE_PATH,
            $this->getCacheId()
        );

        if (!$isCached) {
            $this->smarty->assign(
                $this->getWidgetVariables($hookName, $configuration)
            );
        }

        return $this->fetch(
            self::TEMPLATE_PATH,
            $this->getCacheId()
        );
    }

    public function getWidgetVariables($hookName, array $configuration)
    {
        return array(
            'products' => $this->getViewedProducts(),
        );
    }
}
