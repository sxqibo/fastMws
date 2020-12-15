<?php

namespace Sxqibo\MCS;

use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use League\Csv\Reader;
use League\Csv\Writer;
use Spatie\ArrayToXml\ArrayToXml;
use SplTempFileObject;

class MWSClient
{
    const SIGNATURE_METHOD = 'HmacSHA256';
    const SIGNATURE_VERSION = '2';
    const DATE_FORMAT = "Y-m-d\TH:i:s.\\0\\0\\0\\Z";
    const APPLICATION_NAME = 'MCS/MwsClient';
    protected $config = [
        'Seller_Id' => null,
        'Marketplace_Id' => null,
        'Access_Key_ID' => null,
        'Secret_Access_Key' => null,
        'MWSAuthToken' => null,
        'Application_Version' => '0.0.*'
    ];
    protected $MarketplaceIds = [
        'A2EUQ1WTGCTBG2' => 'mws.amazonservices.ca',
        'ATVPDKIKX0DER' => 'mws.amazonservices.com',
        'A1AM78C64UM0Y8' => 'mws.amazonservices.com.mx',
        'A1PA6795UKMFR9' => 'mws-eu.amazonservices.com',
        'A1RKKUPIHCS9HS' => 'mws-eu.amazonservices.com',
        'A13V1IB3VIYZZH' => 'mws-eu.amazonservices.com',
        'A21TJRUUN4KGV' => 'mws.amazonservices.in',
        'APJ6JRA9NG5V4' => 'mws-eu.amazonservices.com',
        'A1F83G8C2ARO7P' => 'mws-eu.amazonservices.com',
        'A1VC38T7YXB528' => 'mws.amazonservices.jp',
        'A39IBJ37TRP1C6' => 'mws.amazonservices.com.au',
        'A2Q3Y263D00KWC' => 'mws.amazonservices.com',
        'A1805IZSGTT6HS' => 'mws-eu.amazonservices.com',
        'ARBP9OOSHTCHU' => 'mws-eu.amazonservices.com',
        'A17E79C6D8DWNP' => 'mws.amazonservices.com',
        'A33AVAJ2PDY3EV' => 'mws.amazonservices.com',
        'A19VAU5U5O7RUS' => 'mws-fe.amazonservices.com',
        'A2VIGQ35RCS4UG' => 'mws.amazonservices.ae',
        'A2NODRKZP88ZB9' => 'mws-eu.amazonservices.com',
    ];
    protected $debugNextFeed = false;
    protected $client = null;

    public function __construct(array $config)
    {
        foreach ($config as $key => $value) {
            if (array_key_exists($key, $this->config)) {
                $this->config[$key] = $value;
            }
        }
        $required_keys = [
            'Marketplace_Id',
            'Seller_Id',
            'Access_Key_ID',
            'Secret_Access_Key'
        ];
        foreach ($required_keys as $key) {
            if (is_null($this->config[$key])) {
                throw new Exception('Required field ' . $key . ' is not set');
            }
        }
        if (!isset($this->MarketplaceIds[$this->config['Marketplace_Id']])) {
            throw new Exception('Invalid Marketplace Id');
        }
        $this->config['Application_Name'] = self::APPLICATION_NAME;
        $this->config['Region_Host'] = $this->MarketplaceIds[$this->config['Marketplace_Id']];
        $this->config['Region_Url'] = 'https://' . $this->config['Region_Host'];
    }

    /**
     * Call this method to get the raw feed instead of sending it
     */
    public function debugNextFeed()
    {
        $this->debugNextFeed = true;
    }

    /**
     * A method to quickly check if the supplied credentials are valid
     * @return boolean
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function validateCredentials()
    {
        try {
            $this->ListOrderItems('validate');
        } catch (Exception $e) {
            if ($e->getMessage() == 'Invalid AmazonOrderId: validate' || $e->getMessage() == 'The order id you have requested is not valid.') {
                return true;
            } else {
                return false;
            }
        }
    }

    /**
     * Returns the current competitive price of a product, based on ASIN.
     * @param array [$asin_array = []]
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetCompetitivePricingForASIN($asin_array = [])
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');
        }
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }
        $response = $this->request(
            'GetCompetitivePricingForASIN',
            $query
        );
        if (isset($response['GetCompetitivePricingForASINResult'])) {
            $response = $response['GetCompetitivePricingForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }
        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'];
            }
        }
        return $array;
    }

    /**
     * Returns the current competitive price of a product, based on SKU.
     * @param array [$sku_array = []]
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetCompetitivePricingForSKU($sku_array = [])
    {
        if (count($sku_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');
        }
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        foreach ($sku_array as $key) {
            $query['SellerSKUList.SellerSKU.' . $counter] = $key;
            $counter++;
        }
        $response = $this->request(
            'GetCompetitivePricingForSKU',
            $query
        );
        if (isset($response['GetCompetitivePricingForSKUResult'])) {
            $response = $response['GetCompetitivePricingForSKUResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }
        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'])) {
                $array[$product['Product']['Identifiers']['SKUIdentifier']['SellerSKU']]['Price'] = $product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'];
                $array[$product['Product']['Identifiers']['SKUIdentifier']['SellerSKU']]['Rank'] = !empty($product['Product']['SalesRankings']['SalesRank']) ?? $product['Product']['SalesRankings']['SalesRank'][1];
                $array[$product['Product']['Identifiers']['SKUIdentifier']['SellerSKU']]['attributes'] = $product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['@attributes'];
                $array[$product['Product']['Identifiers']['SKUIdentifier']['SellerSKU']]['MarketplaceASIN'] = $product['Product']['Identifiers']['MarketplaceASIN'];
            }
        }
        return $array;
    }

    /**
     * Returns lowest priced offers for a single product, based on ASIN.
     * @param string $asin
     * @param string [$ItemCondition = 'New'] Should be one in: New, Used, Collectible, Refurbished, Club
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetLowestPricedOffersForASIN($asin, $ItemCondition = 'New')
    {
        $query = [
            'ASIN' => $asin,
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ItemCondition' => $ItemCondition
        ];
        return $this->request('GetLowestPricedOffersForASIN', $query);
    }

    /**
     * Returns pricing information for your own offer listings, based on SKU.
     * @param array  [$sku_array = []]
     * @param string [$ItemCondition = null]
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetMyPriceForSKU($sku_array = [], $ItemCondition = null)
    {
        if (count($sku_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');
        }
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        foreach ($sku_array as $key) {
            $query['SellerSKUList.SellerSKU.' . $counter] = $key;
            $counter++;
        }
        $response = $this->request(
            'GetMyPriceForSKU',
            $query
        );
        if (isset($response['GetMyPriceForSKUResult'])) {
            $response = $response['GetMyPriceForSKUResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }
        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success') {
                if (isset($product['Product']['Offers']['Offer'])) {
                    $array[$product['@attributes']['SellerSKU']] = $product['Product']['Offers']['Offer'];
                } else {
                    $array[$product['@attributes']['SellerSKU']] = [];
                }
            } else {
                $array[$product['@attributes']['SellerSKU']] = false;
            }
        }
        return $array;
    }

    /**
     * Returns pricing information for your own offer listings, based on ASIN.
     * @param array [$asin_array = []]
     * @param string [$ItemCondition = null]
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetMyPriceForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');
        }
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }
        $response = $this->request(
            'GetMyPriceForASIN',
            $query
        );
        if (isset($response['GetMyPriceForASINResult'])) {
            $response = $response['GetMyPriceForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }
        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success' && isset($product['Product']['Offers']['Offer'])) {
                $array[$product['@attributes']['ASIN']] = $product['Product']['Offers']['Offer'];
            } else {
                $array[$product['@attributes']['ASIN']] = false;
            }
        }
        return $array;
    }

    /**
     * Returns pricing information for the lowest-price active offer listings for up to 20 products, based on ASIN.
     * @param array [$asin_array = []] array of ASIN values
     * @param array [$ItemCondition = null] Should be one in: New, Used, Collectible, Refurbished, Club. Default: All
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetLowestOfferListingsForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');
        }
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }
        $response = $this->request(
            'GetLowestOfferListingsForASIN',
            $query
        );
        if (isset($response['GetLowestOfferListingsForASINResult'])) {
            $response = $response['GetLowestOfferListingsForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }
        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['LowestOfferListings']['LowestOfferListing'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['LowestOfferListings']['LowestOfferListing'];
            } else {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = false;
            }
        }
        return $array;
    }

    /**
     * Returns orders created or updated during a time frame that you specify.
     * @param DateTime $from
     * @param boolean $allMarketplaces , list orders from all marketplaces
     * @param array $states , an array containing orders states you want to filter on
     * @param string $FulfillmentChannels
     * @param DateTime|null $till
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListOrders(
        DateTime $from,
        $allMarketplaces = false,
        $states = [
            'Unshipped',
            'PartiallyShipped'
        ],
        $FulfillmentChannels = 'MFN',
        DateTime $till = null
    )
    {
        $query = [
            'CreatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp())
        ];
        if ($till !== null) {
            $query['CreatedBefore'] = gmdate(self::DATE_FORMAT, $till->getTimestamp());
        }
        $counter = 1;
        foreach ($states as $status) {
            $query['OrderStatus.Status.' . $counter] = $status;
            $counter = $counter + 1;
        }
        if ($allMarketplaces == true) {
            $counter = 1;
            foreach ($this->MarketplaceIds as $key => $value) {
                $query['MarketplaceId.Id.' . $counter] = $key;
                $counter = $counter + 1;
            }
        }
        if (is_array($FulfillmentChannels)) {
            $counter = 1;
            foreach ($FulfillmentChannels as $fulfillmentChannel) {
                $query['FulfillmentChannel.Channel.' . $counter] = $fulfillmentChannel;
                $counter = $counter + 1;
            }
        } else {
            $query['FulfillmentChannel.Channel.1'] = $FulfillmentChannels;
        }
        $response = $this->request('ListOrders', $query);
        if (isset($response['ListOrdersResult']['Orders']['Order'])) {
            if (isset($response['ListOrdersResult']['NextToken'])) {
                $data['ListOrders'] = $response['ListOrdersResult']['Orders']['Order'];
                $data['NextToken'] = $response['ListOrdersResult']['NextToken'];
                return $data;
            }

            $response = $response['ListOrdersResult']['Orders']['Order'];

            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }

            return $response;

        } else {
            return [];
        }
    }

    /**
     * Returns orders created or updated during a time frame that you specify.
     * @param string $nextToken
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListOrdersByNextToken($nextToken)
    {
        $query = [
            'NextToken' => $nextToken,
        ];
        $response = $this->request(
            'ListOrdersByNextToken',
            $query
        );
        if (isset($response['ListOrdersByNextTokenResult']['Orders']['Order'])) {
            if (isset($response['ListOrdersByNextTokenResult']['NextToken'])) {
                $data['ListOrders'] = $response['ListOrdersByNextTokenResult']['Orders']['Order'];
                $data['NextToken'] = $response['ListOrdersByNextTokenResult']['NextToken'];
                return $data;
            }
            $response = $response['ListOrdersByNextTokenResult']['Orders']['Order'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }
            return $response;
        } else {
            return [];
        }
    }

    /**
     * Returns an order based on the AmazonOrderId values that you specify.
     * @param string $AmazonOrderId
     * @return bool if the order is found, false if not
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetOrder($AmazonOrderId)
    {
        $response = $this->request('GetOrder', [
            'AmazonOrderId.Id.1' => $AmazonOrderId
        ]);
        if (isset($response['GetOrderResult']['Orders']['Order'])) {
            return $response['GetOrderResult']['Orders']['Order'];
        } else {
            return false;
        }
    }

    /**
     * Returns order items based on the AmazonOrderId that you specify.
     * @param string $AmazonOrderId
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListOrderItems($AmazonOrderId)
    {
        $response = $this->request('ListOrderItems', [
            'AmazonOrderId' => $AmazonOrderId
        ]);
        $result = array_values($response['ListOrderItemsResult']['OrderItems']);
        if (isset($result[0]['QuantityOrdered'])) {
            return $result;
        } else {
            return $result[0];
        }
    }

    /**
     * Returns the parent product categories that a product belongs to, based on SellerSKU.
     * @param string $SellerSKU
     * @return bool if found, false if not found
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetProductCategoriesForSKU($SellerSKU)
    {
        $result = $this->request('GetProductCategoriesForSKU', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'SellerSKU' => $SellerSKU
        ]);
        if (isset($result['GetProductCategoriesForSKUResult']['Self'])) {
            return $result['GetProductCategoriesForSKUResult']['Self'];
        } else {
            return false;
        }
    }

    /**
     * Returns the parent product categories that a product belongs to, based on ASIN.
     * @param string $ASIN
     * @return bool if found, false if not found
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetProductCategoriesForASIN($ASIN)
    {
        $result = $this->request('GetProductCategoriesForASIN', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ASIN' => $ASIN
        ]);
        if (isset($result['GetProductCategoriesForASINResult']['Self'])) {
            return $result['GetProductCategoriesForASINResult']['Self'];
        } else {
            return false;
        }
    }

    /**
     * Returns a list of products and their attributes, based on a list of ASIN, GCID, SellerSKU, UPC, EAN, ISBN, and JAN values.
     * @param array $asin_array A list of id's
     * @param string [$type = 'ASIN']  the identifier name
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetMatchingProductForId(array $asin_array, $type = 'ASIN')
    {
        $asin_array = array_unique($asin_array);
        if (count($asin_array) > 5) {
            throw new Exception('Maximum number of id\'s = 5');
        }
        $counter = 1;
        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'IdType' => $type
        ];
        foreach ($asin_array as $asin) {
            $array['IdList.Id.' . $counter] = $asin;
            $counter++;
        }
        $response = $this->request(
            'GetMatchingProductForId',
            $array,
            null,
            true
        );
        $languages = [
            'de-DE',
            'en-EN',
            'es-ES',
            'fr-FR',
            'it-IT',
            'en-US'
        ];
        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];
        foreach ($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }
        $replace['ns2:'] = '';
        $response = $this->xmlToArray(strtr($response, $replace));
        if (isset($response['GetMatchingProductForIdResult']['@attributes'])) {
            $response['GetMatchingProductForIdResult'] = [
                0 => $response['GetMatchingProductForIdResult']
            ];
        }
        $found = [];
        $not_found = [];
        if (isset($response['GetMatchingProductForIdResult']) && is_array($response['GetMatchingProductForIdResult'])) {
            foreach ($response['GetMatchingProductForIdResult'] as $result) {
                //print_r($product);exit;
                $asin = $result['@attributes']['Id'];
                if ($result['@attributes']['status'] != 'Success') {
                    $not_found[] = $asin;
                } else {
                    if (isset($result['Products']['Product']['AttributeSets'])) {
                        $products[0] = $result['Products']['Product'];
                    } else {
                        $products = $result['Products']['Product'];
                    }
                    foreach ($products as $product) {
                        $array = [];
                        if (isset($product['Identifiers']['MarketplaceASIN']['ASIN'])) {
                            $array["ASIN"] = $product['Identifiers']['MarketplaceASIN']['ASIN'];
                        }
                        foreach ($product['AttributeSets']['ItemAttributes'] as $key => $value) {
                            if (is_string($key) && is_string($value)) {
                                $array[$key] = $value;
                            }
                        }
                        if (isset($product['AttributeSets']['ItemAttributes']['Feature'])) {
                            $array['Feature'] = $product['AttributeSets']['ItemAttributes']['Feature'];
                        }
                        if (isset($product['AttributeSets']['ItemAttributes']['PackageDimensions'])) {
                            $array['PackageDimensions'] = array_map(
                                'floatval',
                                $product['AttributeSets']['ItemAttributes']['PackageDimensions']
                            );
                        }
                        if (isset($product['AttributeSets']['ItemAttributes']['ListPrice'])) {
                            $array['ListPrice'] = $product['AttributeSets']['ItemAttributes']['ListPrice'];
                        }
                        if (isset($product['AttributeSets']['ItemAttributes']['SmallImage'])) {
                            $image = $product['AttributeSets']['ItemAttributes']['SmallImage']['URL'];
                            $array['medium_image'] = $image;
                            $array['small_image'] = str_replace('._SL75_', '._SL50_', $image);
                            $array['large_image'] = str_replace('._SL75_', '', $image);;
                        }
                        if (isset($product['Relationships']['VariationParent']['Identifiers']['MarketplaceASIN']['ASIN'])) {
                            $array['Parentage'] = 'child';
                            $array['Relationships'] = $product['Relationships']['VariationParent']['Identifiers']['MarketplaceASIN']['ASIN'];
                        }
                        if (isset($product['Relationships']['VariationChild'])) {
                            $array['Parentage'] = 'parent';
                        }
                        if (isset($product['SalesRankings']['SalesRank'])) {
                            $array['SalesRank'] = $product['SalesRankings']['SalesRank'];
                        }
                        $found[$asin][] = $array;
                    }
                }
            }
        }
        return [
            'found' => $found,
            'not_found' => $not_found
        ];
    }

    /**
     * Returns a list of products and their attributes, ordered by relevancy, based on a search query that you specify.
     * @param string $query the open text query
     * @param string [$query_context_id = null] the identifier for the context within which the given search will be performed. see: http://docs.developer.amazonservices.com/en_US/products/Products_QueryContextIDs.html
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListMatchingProducts($query, $query_context_id = null)
    {
        if (trim($query) == "") {
            throw new Exception('Missing query');
        }
        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'Query' => urlencode($query),
            'QueryContextId' => $query_context_id
        ];
        $response = $this->request(
            'ListMatchingProducts',
            $array,
            null,
            true
        );
        $languages = [
            'de-DE',
            'en-EN',
            'es-ES',
            'fr-FR',
            'it-IT',
            'en-US'
        ];
        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];
        foreach ($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }
        $replace['ns2:'] = '';
        $response = $this->xmlToArray(strtr($response, $replace));
        if (isset($response['ListMatchingProductsResult'])) {
            return $response['ListMatchingProductsResult'];
        } else {
            return ['ListMatchingProductsResult' => []];
        }
    }

    /**
     * Returns a list of reports that were created in the previous 90 days.
     * @param array [$ReportTypeList = []]
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetReportList($ReportTypeList = [])
    {
        $array = [];
        $counter = 1;
        if (count($ReportTypeList)) {
            foreach ($ReportTypeList as $ReportType) {
                $array['ReportTypeList.Type.' . $counter] = $ReportType;
                $counter++;
            }
        }
        return $this->request('GetReportList', $array);
    }

    /**
     * Returns your active recommendations for a specific category or for all categories for a specific marketplace.
     * @param string [$RecommendationCategory = null] One of: Inventory, Selection, Pricing, Fulfillment, ListingQuality, GlobalSelling, Advertising
     * @return array/false if no result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListRecommendations($RecommendationCategory = null)
    {
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        if (!is_null($RecommendationCategory)) {
            $query['RecommendationCategory'] = $RecommendationCategory;
        }
        $result = $this->request('ListRecommendations', $query);
        if (isset($result['ListRecommendationsResult'])) {
            return $result['ListRecommendationsResult'];
        } else {
            return false;
        }
    }

    /**
     * Returns a list of marketplaces that the seller submitting the request can sell in, and a list of participations that include seller-specific information in that marketplace
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListMarketplaceParticipations()
    {
        $result = $this->request('ListMarketplaceParticipations');
        if (isset($result['ListMarketplaceParticipationsResult'])) {
            return $result['ListMarketplaceParticipationsResult'];
        } else {
            return $result;
        }
    }

    /**
     * Delete product's based on SKU
     * @param array $array array containing sku's
     * @return array feed submission result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function deleteProductBySKU(array $array)
    {
        $feed = [
            'MessageType' => 'Product',
            'Message' => []
        ];
        foreach ($array as $sku) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'OperationType' => 'Delete',
                'Product' => [
                    'SKU' => $sku
                ]
            ];
        }
        return $this->SubmitFeed('_POST_PRODUCT_DATA_', $feed);
    }

    /**
     * Update a product's stock quantity
     * @param array $array array containing sku as key and quantity as value
     * @return array feed submission result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateStock(array $array)
    {
        $feed = [
            'MessageType' => 'Inventory',
            'Message' => []
        ];
        foreach ($array as $sku => $quantity) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'OperationType' => 'Update',
                'Inventory' => [
                    'SKU' => $sku,
                    'Quantity' => (int)$quantity
                ]
            ];
        }
        return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);
    }

    /**
     * Update a product's stock quantity
     *
     * @param array $array array containing arrays with next keys: [sku, quantity, latency]
     * @return array feed submission result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateStockWithFulfillmentLatency(array $array)
    {
        $feed = [
            'MessageType' => 'Inventory',
            'Message' => []
        ];
        foreach ($array as $item) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'OperationType' => 'Update',
                'Inventory' => [
                    'SKU' => $item['sku'],
                    'Quantity' => (int)$item['quantity'],
                    'FulfillmentLatency' => $item['latency']
                ]
            ];
        }
        return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);
    }

    /**
     * Update a product's price
     * @param array $standardprice an array containing sku as key and price as value
     * @param array|null $saleprice
     * @return array feed submission result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updatePrice(array $standardprice, array $saleprice = null)
    {
        $feed = [
            'MessageType' => 'Price',
            'Message' => []
        ];
        foreach ($standardprice as $sku => $price) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'Price' => [
                    'SKU' => $sku,
                    'StandardPrice' => [
                        '_value' => strval($price),
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]
                    ]
                ]
            ];
            if (isset($saleprice[$sku]) && is_array($saleprice[$sku])) {
                $feed['Message'][count($feed['Message']) - 1]['Price']['Sale'] = [
                    'StartDate' => $saleprice[$sku]['StartDate']->format(self::DATE_FORMAT),
                    'EndDate' => $saleprice[$sku]['EndDate']->format(self::DATE_FORMAT),
                    'SalePrice' => [
                        '_value' => strval($saleprice[$sku]['SalePrice']),
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]
                    ]
                ];
            }
        }
        return $this->SubmitFeed('_POST_PRODUCT_PRICING_DATA_', $feed);
    }

    /**
     * Update a product's image
     *
     * @param array $array array containing arrays with next keys: [sku, image_type, image_location]
     * @return array|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateImage(array $array)
    {
        $feed = [
            'MessageType' => 'ProductImage',
            'Message' => []
        ];
        foreach ($array as $item) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'OperationType' => 'Update',
                'ProductImage' => [
                    'SKU' => $item['sku'],
                    'ImageType' => $item['image_type'],
                    'ImageLocation' => $item['image_location']
                ]
            ];
        }
        return $this->SubmitFeed('_POST_PRODUCT_IMAGE_DATA_', $feed);
    }

    /**
     * Update a product's Relationship
     *
     * @param array $array array containing arrays with next keys: [parent_sku, relation_list]
     * @return array|string
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function updateRelationship(array $array)
    {
        $feed = [
            'MessageType' => 'Relationship',
            'Message' => []
        ];
        foreach ($array as $item) {
            $relationList = $item['relation_list'];
            $newData = [
                'MessageID' => rand(),
                'OperationType' => 'Update',
                'Relationship' => [
                    'ParentSKU' => $item['parent_sku'],
                    'Relation' => [],
                ]
            ];

            foreach ($relationList as $relation) {
                $newData['Relationship']['Relation'][] = [
                    'SKU' => $relation['sku'],
                    'Type' => $relation['type'] ?? 'Variation',
                ];
            }

            $feed['Message'][] = $newData;
        }

        return $this->SubmitFeed('_POST_PRODUCT_RELATIONSHIP_DATA_', $feed);
    }

    /**
     * Returns the feed processing report and the Content-MD5 header.
     * @param string $FeedSubmissionId
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetFeedSubmissionResult($FeedSubmissionId)
    {
        $result = $this->request('GetFeedSubmissionResult', [
            'FeedSubmissionId' => $FeedSubmissionId
        ]);
        if (isset($result['Message']['ProcessingReport'])) {
            return $result['Message']['ProcessingReport'];
        } else {
            return $result;
        }
    }

    /**
     * Uploads a feed for processing by Amazon MWS.
     * @param string $FeedType (http://docs.developer.amazonservices.com/en_US/feeds/Feeds_FeedType.html)
     * @param mixed $feedContent Array will be converted to xml using https://github.com/spatie/array-to-xml. Strings will not be modified.
     * @param boolean $debug Return the generated xml and don't send it to amazon
     * @param array $options
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function SubmitFeed($FeedType, $feedContent, $debug = false, $options = [])
    {
        if (is_array($feedContent)) {
            $feedContent = $this->arrayToXml(
                array_merge([
                    'Header' => [
                        'DocumentVersion' => 1.01,
                        'MerchantIdentifier' => $this->config['Seller_Id']
                    ]
                ], $feedContent)
            );
        }

        if ($debug === true) {
            return $feedContent;
        } else {
            if ($this->debugNextFeed == true) {
                $this->debugNextFeed = false;
                return $feedContent;
            }
        }
        $purgeAndReplace = isset($options['PurgeAndReplace']) ? $options['PurgeAndReplace'] : false;

        $query = [
            'FeedType' => $FeedType,
            'PurgeAndReplace' => ($purgeAndReplace ? 'true' : 'false'),
            'Merchant' => $this->config['Seller_Id'],
            'MarketplaceId.Id.1' => false,
            'SellerId' => false,
        ];
        //if ($FeedType === '_POST_PRODUCT_PRICING_DATA_') {
        $query['MarketplaceIdList.Id.1'] = $this->config['Marketplace_Id'];
        //}
        $response = $this->request(
            'SubmitFeed',
            $query,
            $feedContent
        );
        return $response['SubmitFeedResult']['FeedSubmissionInfo'];
    }

    /**
     * Convert an array to xml
     * @param $array array to convert
     * @param string $customRoot [$customRoot = 'AmazonEnvelope']
     * @return string
     */
    protected function arrayToXml(array $array, $customRoot = 'AmazonEnvelope')
    {
        return ArrayToXml::convert($array, $customRoot, true, 'UTF-8');
    }

    /**
     * Convert an xml string to an array
     * @param string $xmlstring
     * @return array
     */
    protected function xmlToArray($xmlstring)
    {
        return json_decode(json_encode(simplexml_load_string($xmlstring)), true);
    }

    /**
     * Creates a report request and submits the request to Amazon MWS.
     * @param string $report (http://docs.developer.amazonservices.com/en_US/reports/Reports_ReportType.html)
     * @param DateTime [$StartDate = null]
     * @param DateTime [$EndDate = null]
     * @return string ReportRequestId
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function RequestReport($report, $StartDate = null, $EndDate = null)
    {
        $query = [
            'MarketplaceIdList.Id.1' => $this->config['Marketplace_Id'],
            'ReportType' => $report
        ];
        if (!is_null($StartDate)) {
            if (!is_a($StartDate, 'DateTime')) {
                throw new Exception('StartDate should be a DateTime object');
            } else {
                $query['StartDate'] = gmdate(self::DATE_FORMAT, $StartDate->getTimestamp());
            }
        }
        if (!is_null($EndDate)) {
            if (!is_a($EndDate, 'DateTime')) {
                throw new Exception('EndDate should be a DateTime object');
            } else {
                $query['EndDate'] = gmdate(self::DATE_FORMAT, $EndDate->getTimestamp());
            }
        }
        $result = $this->request(
            'RequestReport',
            $query
        );
        if (isset($result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'])) {
            return $result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'];
        } else {
            throw new Exception('Error trying to request report');
        }
    }

    /**
     * Get a report's content
     * @param string $ReportId
     * @return array|bool on succes
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetReport($ReportId)
    {
        $status = $this->GetReportRequestStatus($ReportId);
        if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_NO_DATA_') {
            return [];
        } else {
            if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_') {
                $result = $this->request('GetReport', [
                    'ReportId' => $status['GeneratedReportId']
                ]);
                if (is_string($result)) {
                    $reader = Reader::createFromString($result);
                    $reader->setDelimiter("\t");
                    $reader->setHeaderOffset(0);
                    $headers = $reader->getHeader();
                    $statement = new \League\Csv\Statement;
                    $result = [];
                    foreach ($statement->process($reader) as $row) {
                        $result[] = array_combine($headers, $row);
                    }
                }
                return $result;
            } else {
                return false;
            }
        }
    }

    /**
     * Get a report's processing status
     * @param string $ReportId
     * @return bool if the report is found
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetReportRequestStatus($ReportId)
    {
        $result = $this->request('GetReportRequestList', [
            'ReportRequestIdList.Id.1' => $ReportId
        ]);
        if (isset($result['GetReportRequestListResult']['ReportRequestInfo'])) {
            return $result['GetReportRequestListResult']['ReportRequestInfo'];
        }
        return false;
    }

    /**
     * Get a list's inventory for Amazon's fulfillment
     *
     * @param array $sku_array
     *
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListInventorySupply($sku_array = [])
    {

        if (count($sku_array) > 50) {
            throw new Exception('Maximum amount of SKU\'s for this call is 50');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        foreach ($sku_array as $key) {
            $query['SellerSkus.member.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'ListInventorySupply',
            $query
        );

        $result = [];
        if (isset($response['ListInventorySupplyResult']['InventorySupplyList']['member'])) {
            foreach ($response['ListInventorySupplyResult']['InventorySupplyList']['member'] as $index => $ListInventorySupplyResult) {
                $result[$index] = $ListInventorySupplyResult;
            }
        }

        return $result;
    }

    /**
     * Sets the shipping status of orders
     * @param array $data required data
     * @return array feed submission result
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function setDeliveryState(array $data)
    {
        $feed = [
            'MessageType' => 'OrderFulfillment',
            'Message' => []
        ];
        foreach ($data as $k => $datum) {
            if (!isset($datum["shippingDate"])) {
                $datum["shippingDate"] = date("c");
            }

            if (!isset($datum["carrierCode"]) && !isset($datum["carrierName"])) {
                throw new Exception('Missing required carrier data');
            }

            $feed['Message'][$k] = [
                'MessageID' => rand(),
                "OrderFulfillment" => [
                    "AmazonOrderID" => $datum["orderId"],
                    "FulfillmentDate" => $datum["shippingDate"]
                ]
            ];
            $fulfillmentData = [];


            if (isset($datum["carrierCode"])) {
                $fulfillmentData["CarrierCode"] = $datum["carrierCode"];
            } elseif (isset($datum["carrierName"])) {
                $fulfillmentData["CarrierName"] = $datum["carrierName"];
            }

            if (isset($datum["shippingMethod"])) {
                $fulfillmentData["ShippingMethod"] = $datum["shippingMethod"];
            }


            if (isset($datum["trackingCode"])) {
                $fulfillmentData["ShipperTrackingNumber"] = $datum["trackingCode"];
            }

            if (sizeof($fulfillmentData) > 0) {
                $feed["Message"][$k]["OrderFulfillment"]["FulfillmentData"] = $fulfillmentData;
            }
        }

        $feed = $this->SubmitFeed('_POST_ORDER_FULFILLMENT_DATA_', $feed);

        return $feed;
    }

    /**
     * Post to create or update a product (_POST_FLAT_FILE_LISTINGS_DATA_)
     * @param object|array $MWSProduct or array of Custom objects
     * @param string $template
     * @param null $version
     * @param null $signature
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function postProduct($MWSProduct, $template = 'Custom', $version = null, $signature = null)
    {
        if (!is_array($MWSProduct)) {
            $MWSProduct = [$MWSProduct];
        }
        $csv = Writer::createFromFileObject(new SplTempFileObject());
        $csv->setDelimiter("\t");

        $csv->insertOne(['TemplateType=' . $template, 'Version=' . $version, 'TemplateSignature=' . $signature]);

        $header = array_keys($MWSProduct[0]->toArray());

        $csv->insertOne($header);
        $csv->insertOne($header);

        foreach ($MWSProduct as $product) {
            $csv->insertOne(array_values($product->toArray()));
        }

        return $this->SubmitFeed('_POST_FLAT_FILE_LISTINGS_DATA_', $csv);
    }

    /**
     * Post to create or update a product (_POST_FLAT_FILE_LISTINGS_DATA_)
     * @param array $MWSProduct
     * @param string $template
     * @param null $version
     * @return array
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function postFollowProduct($MWSProduct, $template = 'Offer', $version = null)
    {
        if (!is_array($MWSProduct)) {
            $MWSProduct = [$MWSProduct];
        }
        $csv = Writer::createFromFileObject(new SplTempFileObject());
        $csv->setDelimiter("\t");

        $csv->insertOne(['TemplateType=' . $template, 'Version=' . $version]);

        $header = array_keys($MWSProduct[0]);
        $csv->insertOne($header);

        foreach ($MWSProduct as $product) {
            $csv->insertOne(array_values($product));
        }

        return $this->SubmitFeed('_POST_FLAT_FILE_LISTINGS_DATA_', $csv);
    }

    /**
     * Returns financial events for a given order by it's id
     *
     * @param string $AmazonOrderId
     * @return array
     *
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListFinancialEventsByOrderId($AmazonOrderId)
    {
        $query = ['AmazonOrderId' => $AmazonOrderId];
        $response = $this->request('ListFinancialEvents', $query);
        return $this->processListFinancialEventsResponse($response);
    }

    /**
     * Returns financial events for a given financial event group id
     *
     * @param $groupId
     * @return array
     *
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListFinancialEventsByEventGroupId($groupId)
    {
        $query = ['FinancialEventGroupId' => $groupId];
        $response = $this->request('ListFinancialEvents', $query);
        return $this->processListFinancialEventsResponse($response);
    }

    /**
     * Returns financial events for a given financial events date range
     *
     * @param DateTime $from
     * @param DateTime|null $till
     *
     * @return array
     *
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListFinancialEventsByDateRange(DateTime $from, DateTime $till = null)
    {
        $query = [
            'PostedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp())
        ];
        if (!is_null($till)) {
            $query['PostedBefore'] = gmdate(self::DATE_FORMAT, $till->getTimestamp());
        }
        $response = $this->request('ListFinancialEvents', $query);
        return $this->processListFinancialEventsResponse($response);
    }

    /**
     * Processes list financial events response
     *
     * @param array $response
     * @param string $fieldName
     *
     * @return array
     */
    protected function processListFinancialEventsResponse($response, $fieldName = 'ListFinancialEventsResult')
    {
        if (!isset($response[$fieldName]['FinancialEvents'])) {
            return [];
        }
        $data = $response[$fieldName]['FinancialEvents'];
        // We remove empty lists
        $data = array_filter($data, function ($item) {
            return count($item) > 0;
        });
        if (isset($response[$fieldName]['NextToken'])) {
            // Remove ==, I've seen cases when Amazon servers fails otherwise
            $data['ListFinancialEvents'] = $data;
            $data['NextToken'] = rtrim($response[$fieldName]['NextToken'], '=');
            return $data;
        }
        return ['ListFinancialEvents' => $data];
    }

    /**
     * Returns the next page of financial events using the NextToken parameter
     *
     * @param string $nextToken
     * @return array
     *
     * @throws Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function ListFinancialEventsByNextToken($nextToken)
    {
        $query = [
            'NextToken' => $nextToken,
        ];
        $response = $this->request(
            'ListFinancialEventsByNextToken',
            $query
        );
        return $this->processListFinancialEventsResponse($response, 'ListFinancialEventsByNextTokenResult');
    }

    /**
     * @param array $ReportTypeList
     * @param $limit
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetReportRequestList($ReportTypeList = null, $limit = null)
    {
        $array = [];
        $counter = 1;
        if (count($ReportTypeList)) {
            foreach ($ReportTypeList as $ReportType) {
                $array['ReportTypeList.Type.' . $counter] = $ReportType;
                $counter++;
            }
        }
        $array['MaxCount'] = $limit;
        return $this->request('GetReportRequestList', $array);
    }

    /**
     * @param array $ReportTypeList
     * @param $nextToken
     * @param $limit
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function GetReportListByNextToken($ReportTypeList = [], $nextToken = null, $limit = null)
    {
        $array = [];
        $counter = 1;
        if (count($ReportTypeList)) {
            foreach ($ReportTypeList as $ReportType) {
                $array['ReportTypeList.Type.' . $counter] = $ReportType;
                $counter++;
            }
        }
        if ($nextToken != null) {
            $array['NextToken'] = $nextToken;
        }
        $array['MaxCount'] = $limit;
        return $this->request('GetReportListByNextToken', $array);
    }

    /**
     * Request MWS
     *
     * @param $endPoint
     * @param array $query
     * @param null $body
     * @param bool $raw
     * @return string|array
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws Exception
     */
    protected function request($endPoint, array $query = [], $body = null, $raw = false)
    {
        $endPoint = MWSEndPoint::get($endPoint);
        $merge = [
            'Timestamp' => gmdate(self::DATE_FORMAT, time()),
            'AWSAccessKeyId' => $this->config['Access_Key_ID'],
            'Action' => $endPoint['action'],
            //'MarketplaceId.Id.1' => $this->config['Marketplace_Id'],
            'SellerId' => $this->config['Seller_Id'],
            'SignatureMethod' => self::SIGNATURE_METHOD,
            'SignatureVersion' => self::SIGNATURE_VERSION,
            'Version' => $endPoint['date'],
        ];
        $query = array_merge($merge, $query);
        if (!isset($query['MarketplaceId.Id.1'])) {
            $query['MarketplaceId.Id.1'] = $this->config['Marketplace_Id'];
        }
        if (!is_null($this->config['MWSAuthToken']) and $this->config['MWSAuthToken'] != "") {
            $query['MWSAuthToken'] = $this->config['MWSAuthToken'];
        }
        if (isset($query['MarketplaceId'])) {
            unset($query['MarketplaceId.Id.1']);
        }
        if (isset($query['MarketplaceIdList.Id.1'])) {
            unset($query['MarketplaceId.Id.1']);
        }
        try {
            $headers = [
                'Accept' => 'application/xml',
                'x-amazon-user-agent' => $this->config['Application_Name'] . '/' . $this->config['Application_Version']
            ];
            if ($endPoint['action'] === 'SubmitFeed') {
                $headers['Content-MD5'] = base64_encode(md5($body, true));
                if (in_array($this->config['Marketplace_Id'], ['A1VC38T7YXB528'])) {
                    $headers['Content-Type'] = 'text/xml; charset=UTF-8';
                } else {
                    $headers['Content-Type'] = 'text/xml; charset=iso-8859-16';
                }

                $headers['Host'] = $this->config['Region_Host'];
                unset(
                    $query['MarketplaceId.Id.1'],
                    $query['SellerId']
                );
            }
            $requestOptions = [
                'headers' => $headers,
                'body' => $body,
                'curl' => [
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2
                ]
            ];
            ksort($query);
            $query['Signature'] = base64_encode(
                hash_hmac(
                    'sha256',
                    $endPoint['method']
                    . "\n"
                    . $this->config['Region_Host']
                    . "\n"
                    . $endPoint['path']
                    . "\n"
                    . http_build_query($query, null, '&', PHP_QUERY_RFC3986),
                    $this->config['Secret_Access_Key'],
                    true
                )
            );
            $requestOptions['query'] = $query;

            if ($this->client === null) {
                $this->client = new Client();
            }
            $response = $this->client->request(
                $endPoint['method'],
                $this->config['Region_Url'] . $endPoint['path'],
                $requestOptions
            );
            $body = (string)$response->getBody();
            if ($raw) {
                return $body;
            } else {
                if (strpos(strtolower($response->getHeader('Content-Type')[0]), 'xml') !== false) {
                    return $this->xmlToArray($body);
                } else {
                    return $body;
                }
            }
        } catch (BadResponseException $e) {
            if ($e->hasResponse()) {
                $message = $e->getResponse();
                $message = $message->getBody();
                if (strpos($message, '<ErrorResponse') !== false) {
                    $error = simplexml_load_string($message);
                    $message = $error->Error->Message;
                }
            } else {
                $message = 'An error occured';
            }
            throw new Exception($message);
        }
    }

    public function setClient(Client $client)
    {
        $this->client = $client;
    }
}
