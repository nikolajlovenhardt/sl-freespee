<?php

namespace SpotOnLive\Freespee\Services;

use DateTime;
use DateTimeZone;
use SpotOnLive\Freespee\Exceptions\InvalidAPICallException;
use SpotOnLive\Freespee\Exceptions\InvalidCredentialsException;
use SpotOnLive\Freespee\Models\Call;
use SpotOnLive\Freespee\Models\CallInterface;
use SpotOnLive\Freespee\Models\Customer;
use SpotOnLive\Freespee\Models\CustomerAddress;
use SpotOnLive\Freespee\Models\CustomerInterface;
use SpotOnLive\Freespee\Options\ApiOptions;

class FreespeeService implements FreespeeServiceInterface
{
    /** @var ApiOptions */
    protected $config;

    /** @var CurlServiceInterface */
    protected $curlService;

    /** @var DateTimeZone */
    protected $timezone;

    /**
     * @var string
     */
    protected $apiUrl;

    /** @var array */
    protected $credentials = [];

    /**
     * @param array $config
     * @param CurlServiceInterface $curlService
     */
    public function __construct(array $config, CurlServiceInterface $curlService)
    {
        $this->config = new ApiOptions($config);
        $this->curlService = $curlService;
        $this->timezone = new DateTimeZone('UTC');

        // Set API url
        $this->apiUrl = $this->config->get('api_url');

        // Set credentials
        $this->credentials = [
            'username' => $this->config->get('username'),
            'password' => $this->config->get('password'),
        ];
    }

    /**
     * Find customers
     *
     * @param int $page
     * @return array|CustomerInterface[]
     */
    public function findAllCustomers($page = 0)
    {
        $customers = $this->api('/customers', [
            'page' => $page,
        ]);

        /** @var array|CustomerInterface[] $return */
        $return = [];

        foreach ($customers['customers'] as $customerData) {
            $customer = new Customer();
            $customer->setId($customerData['customer_id']);
            $customer->setName($customerData['name']);
            $customer->setCustomerNumber($customerData['custnr']);
            $customer->setCorporateId($customerData['corporateid']);
            $customer->setEmail($customerData['email']);
            $customer->setUuid($customerData['uuid']);
            $customer->setReceiveMonthlyReport((int) $customerData['receive_monthly_report']);
            $customer->setFreespeeCallerId($customerData['freespee_caller_id']);

            // Address
            $address = new CustomerAddress();
            $address->setStreet($customerData['address_street']);
            $address->setZip($customerData['address_zip']);
            $address->setCity($customerData['address_city']);
            $address->setState($customerData['address_state']);
            $address->setCountry($customerData['address_country']);

            $customer->setAddress($address);

            $return[] = $customer;
        }

        return $return;
    }

    /**
     * Get total number of customers
     *
     * @return integer
     */
    public function getTotalNumberOfCustomers()
    {
        $customers = $this->api('/customers');
        return $customers['total'];
    }

    /**
     * Find customer
     *
     * @param integer $id
     * @return CustomerInterface|null
     */
    public function findCustomer($id)
    {
        $customerData = $this->api('/customers', [
            'customer_id' => $id,
        ]);

        if (!isset($customerData['customers'][0])) {
            return null;
        }

        /** @var array $customerData */
        $customerData = $customerData['customers'][0];

        $customer = new Customer();
        $customer->setId($customerData['customer_id']);
        $customer->setName($customerData['name']);
        $customer->setCustomerNumber($customerData['custnr']);
        $customer->setCorporateId($customerData['corporateid']);
        $customer->setEmail($customerData['email']);
        $customer->setUuid($customerData['uuid']);
        $customer->setReceiveMonthlyReport((int) $customerData['receive_monthly_report']);
        $customer->setFreespeeCallerId($customerData['freespee_caller_id']);

        // Address
        $address = new CustomerAddress();
        $address->setStreet($customerData['address_street']);
        $address->setZip($customerData['address_zip']);
        $address->setCity($customerData['address_city']);
        $address->setState($customerData['address_state']);
        $address->setCountry($customerData['address_country']);

        $customer->setAddress($address);

        return $customer;
    }

    /**
     * Find calls
     *
     * @param CustomerInterface $customer
     * @param array $params
     * @return array|CallInterface[]
     */
    public function findCalls(CustomerInterface $customer, $params = [])
    {
        // Set customer id
        $params['customer_id'] = $customer->getId();
        $callsData = $this->api('/statistics/cdrs', $params);

        $return = [
            'total' => $callsData['total'],
            'page' => $callsData['page'],
            'pageSize' => $callsData['pagesize'],
            'numberOfPages' => $callsData['numpages'],
            'results' => [],
        ];

        foreach ($callsData['cdrs'] as $callData) {
            $call = new Call();
            $call->setCdrId($callData['cdr_id']);
            $call->setStart(new DateTime($callData['start'], $this->timezone));
            $call->setDuration($callData['duration']);
            $call->setDurationAdjusted($callData['duration_adjusted']);
            $call->setAnum($callData['anum']);
            $call->setAnumMd5($callData['anum_md5']);
            $call->setBnum($callData['bnum']);
            $call->setCnum($callData['cnum']);
            $call->setCustomerId($callData['customer_id']);
            $call->setSourceId($callData['source_id']);
            $call->setCustomerNumber($callData['custnr']);
            $call->setAnswered($callData['answered']);
            $call->setQuarantined($callData['quarantined']);
            $call->setAnumNdcName($callData['anum_ndc_name']);

            if (isset($params['extended']) && $params['extended']) {
                if ($callData['expire']) {
                    $expire = new DateTime($callData['expire'], $this->timezone);
                    $call->setExpire($expire);
                }

                $call->setSourceName($callData['source_name']);
                $call->setSourceMedia($callData['source_media']);
                $call->setClass($callData['class']);
                $call->setPublisherId($callData['publisher_id']);
                $call->setPartnerPublisherId($callData['partner_publisher_id']);
                $call->setCampaignId($callData['campaign_id']);
                $call->setPartnerCampaignId($callData['partner_campaign_id']);
                $call->setPricingModelId($callData['pricing_model_id']);
                $call->setCommission($callData['commission']);
                $call->setCliId($callData['cli_id']);
                $call->setOrderId($callData['order_id']);
                $call->setRecordingId($callData['recording_id']);
            }

            $return['results'][] = $call;
        }

        return $return;
    }

    /**
     * Call API
     *
     * @param string $url
     * @param array $params
     * @return array
     * @throws InvalidAPICallException
     * @throws InvalidCredentialsException
     */
    public function api($url, array $params = [])
    {
        if (!empty($params)) {
            $queryString = http_build_query($params);
            $url .= "?" . $queryString;
        }

        $credentials = $this->getCredentials();

        $result = $this->curlService->curl(
            $this->getApiUrl() . $url,
            $credentials['username'] . ":" . $credentials['password']
        );

        return $this->parse($result);
    }

    /**
     * Format parameters
     *
     * @param array $params
     * @return array
     */
    public function formatParameters(array $params)
    {
        $return = [];

        foreach ($params as $key => $val) {
            $return[] = $key . ':' . $val;
        }

        return $return;
    }

    /**
     * Parse API response
     *
     * @param string $result
     * @return array
     * @throws InvalidAPICallException
     */
    public function parse($result)
    {
        $array = json_decode($result, true);

        if (isset($array['errors'])) {
            throw new InvalidAPICallException(
                sprintf(
                    _('Freespee API Error: %s'),
                    json_encode($array['errors'])
                )
            );
        }

        return $array;
    }

    /**
     * Get freespee credentials
     *
     * @return array
     * @throws InvalidCredentialsException
     */
    protected function getCredentials()
    {
        $credentials = $this->credentials;

        if (is_null($credentials['username']) || is_null($credentials['password'])) {
            throw new InvalidCredentialsException('Please provide your freespee credentials');
        }

        return $credentials;
    }

    /**
     * Set/override default credentials
     *
     * @param string $username
     * @param string $password
     */
    public function setCredentials($username, $password)
    {
        $this->credentials = [
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * Get API url
     *
     * @return string
     */
    public function getApiUrl()
    {
        return $this->apiUrl;
    }

    /**
     * Set/override default API url
     *
     * @param string $apiUrl
     */
    public function setApiUrl($apiUrl)
    {
        $this->apiUrl = $apiUrl;
    }

    /**
     * @param ApiOptions $config
     */
    public function setConfig($config)
    {
        $this->config = $config;
    }

    /**
     * @return ApiOptions
     */
    public function getConfig()
    {
        return $this->config;
    }
}
