<?php
/**
 * Copyright © 2017 Magmodules.eu. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Mollie\Payment\Model;

use Mollie\Payment\Model\Mollie as MollieModel;
use Mollie\Payment\Helper\General as MollieHelper;
use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\Escaper;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class MollieConfigProvider implements ConfigProviderInterface
{

    const XML_PATH_IMAGES = 'payment/mollie_general/payment_images';

    protected $methodCodes = [
        'mollie_methods_bancontact',
        'mollie_methods_banktransfer',
        'mollie_methods_belfius',
        'mollie_methods_bitcoin',
        'mollie_methods_creditcard',
        'mollie_methods_ideal',
        'mollie_methods_kbc',
        'mollie_methods_paypal',
        'mollie_methods_paysafecard',
        'mollie_methods_sofort',
        'mollie_methods_giftcard'
    ];

    protected $methods = [];
    protected $escaper;
    protected $assetRepository;
    protected $scopeConfig;
    protected $storeManager;
    protected $mollieModel;
    protected $mollieHelper;

    /**
     * MollieConfigProvider constructor.
     *
     * @param Mollie                $mollieModel
     * @param MollieHelper          $mollieHelper
     * @param PaymentHelper         $paymentHelper
     * @param AssetRepository       $assetRepository
     * @param ScopeConfigInterface  $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param Escaper               $escaper
     */
    public function __construct(
        MollieModel $mollieModel,
        MollieHelper $mollieHelper,
        PaymentHelper $paymentHelper,
        AssetRepository $assetRepository,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        Escaper $escaper
    ) {
        $this->mollieModel = $mollieModel;
        $this->mollieHelper = $mollieHelper;
        $this->escaper = $escaper;
        $this->assetRepository = $assetRepository;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;

        foreach ($this->methodCodes as $code) {
            $this->methods[$code] = $paymentHelper->getMethodInstance($code);
        }
    }

    /**
     * Config Data for checkout
     *
     * @return array
     */
    public function getConfig()
    {
        $config = [];
        $apiKey = $this->mollieHelper->getApiKey();

        try {
            $mollieApi = $this->mollieModel->loadMollieApi($apiKey);
            $activeMethods = $this->getActiveMethods($mollieApi);
        } catch (\Exception $e) {
            $mollieApi = '';
            $this->mollieHelper->addTolog('error', $e->getMessage());
            $activeMethods = [];
        }

        foreach ($this->methodCodes as $code) {
            if ($this->methods[$code]->isAvailable()) {
                if (!empty($activeMethods[$code])) {
                    $config['payment']['isActive'][$code] = true;
                    $config['payment']['instructions'][$code] = $this->getInstructions($code);
                    $config['payment']['image'][$code] = (isset($activeMethods[$code]['image']) ? $activeMethods[$code]['image'] : '');
                    $config['payment']['min'][$code] = (isset($activeMethods[$code]['min']) ? $activeMethods[$code]['min'] : '');
                    $config['payment']['max'][$code] = (isset($activeMethods[$code]['max']) ? $activeMethods[$code]['max'] : '');
                    if ($code == 'mollie_methods_ideal') {
                        $config['payment']['issuers'][$code] = $this->getIdealIssuers($mollieApi);
                    }
                    if ($code == 'mollie_methods_giftcard') {
                        $config['payment']['issuers'][$code] = $this->getGiftcardIssuers($mollieApi);
                        if (empty($config['payment']['issuers'][$code])) {
                            $config['payment']['isActive'][$code] = false;
                        }
                    }
                } else {
                    $config['payment']['isActive'][$code] = false;
                }
            } else {
                $config['payment']['isActive'][$code] = false;
            }
        }

        return $config;
    }

    /**
     * @param $mollieApi
     *
     * @return array
     */
    public function getActiveMethods($mollieApi)
    {
        $methods = [];

        try {
            $apiMethods = $mollieApi->methods->all();
            foreach ($apiMethods->data as $method) {
                if ($method->id == 'mistercash') {
                    $methodId = 'mollie_methods_bancontact';
                } else {
                    $methodId = 'mollie_methods_' . $method->id;
                }
                $methods[$methodId] = [
                    'min'   => $method->amount->minimum,
                    'max'   => $method->amount->maximum,
                    'image' => $method->image->normal
                ];
            }
        } catch (\Exception $e) {
            $this->mollieHelper->addTolog('error', 'Function: getActiveMethods: ' . $e->getMessage());
        }

        return $methods;
    }

    /**
     * Instruction data
     *
     * @param $code
     *
     * @return string
     */
    protected function getInstructions($code)
    {
        return nl2br($this->escaper->escapeHtml($this->methods[$code]->getInstructions()));
    }

    /**
     * Get list of iDeal Issuers
     *
     * @param $mollieApi
     *
     * @return array|bool
     */
    public function getIdealIssuers($mollieApi)
    {
        if ($issuers = $this->mollieModel->getIdealIssuers($mollieApi)) {
            return $issuers;
        }
        return [];
    }

    /**
     * Get list of Giftcard Issuers
     *
     * @param $mollieApi
     *
     * @return array|bool
     */
    public function getGiftcardIssuers($mollieApi)
    {
        if ($issuers = $this->mollieModel->getGiftcardIssuers($mollieApi)) {
            return $issuers;
        }
        return [];
    }

    /**
     * Get Store Config Value
     *
     * @param $path
     *
     * @return mixed
     */
    public function getStoreConfig($path)
    {
        $storeId = $this->storeManager->getStore()->getId();
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
