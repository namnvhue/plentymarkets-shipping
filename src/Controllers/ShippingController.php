<?php

namespace ShippingTutorial\Controllers;

use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Cloud\Storage\Models\StorageObject;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Shipping\Contracts\ParcelServicePresetRepositoryContract;
use Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Order\Shipping\PackageType\Contracts\ShippingPackageTypeRepositoryContract;
use Plenty\Modules\Order\Shipping\ParcelService\Models\ParcelServicePreset;
use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;

/**
 * Class ShippingController
 */
class ShippingController extends Controller {
  use Loggable;

  /**
   * @var LibraryCallContract
   */
  public $library;
  /**
   *
   * @var Request
   */
  private $request;

  /**
   *
   * @var OrderRepositoryContract $orderRepository
   */
  private $orderRepository;

  /**
   *
   * @var AddressRepositoryContract $addressRepository
   */
  private $addressRepository;

  /**
   *
   * @var OrderShippingPackageRepositoryContract $orderShippingPackage
   */
  private $orderShippingPackage;

  /**
   *
   * @var ShippingInformationRepositoryContract
   */
  private $shippingInformationRepositoryContract;

  /**
   *
   * @var StorageRepositoryContract $storageRepository
   */
  private $storageRepository;

  /**
   *
   * @var ShippingPackageTypeRepositoryContract
   */
  private $shippingPackageTypeRepositoryContract;

  /**
   *
   * @var array
   */
  private $createOrderResult = [];

  /**
   *
   * @var ConfigRepository
   */
  private $config;

  /**
   * ShipmentController constructor.
   *
   * @param Request                                $request
   * @param OrderRepositoryContract                $orderRepository
   * @param AddressRepositoryContract              $addressRepositoryContract
   * @param OrderShippingPackageRepositoryContract $orderShippingPackage
   * @param StorageRepositoryContract              $storageRepository
   * @param ShippingInformationRepositoryContract  $shippingInformationRepositoryContract
   * @param ShippingPackageTypeRepositoryContract  $shippingPackageTypeRepositoryContract
   * @param ConfigRepository                       $config
   */
  public function __construct(
      Request $request, OrderRepositoryContract $orderRepository, AddressRepositoryContract $addressRepositoryContract, OrderShippingPackageRepositoryContract $orderShippingPackage,
      StorageRepositoryContract $storageRepository, ShippingInformationRepositoryContract $shippingInformationRepositoryContract,
      ShippingPackageTypeRepositoryContract $shippingPackageTypeRepositoryContract, ConfigRepository $config,
      LibraryCallContract $libraryCallContract

  ) {
    $this->request              = $request;
    $this->orderRepository      = $orderRepository;
    $this->addressRepository    = $addressRepositoryContract;
    $this->orderShippingPackage = $orderShippingPackage;
    $this->storageRepository    = $storageRepository;

    $this->shippingInformationRepositoryContract = $shippingInformationRepositoryContract;
    $this->shippingPackageTypeRepositoryContract = $shippingPackageTypeRepositoryContract;

    $this->config  = $config;
    $this->library = $libraryCallContract;
  }

  /**
   * Registers shipment(s)
   *
   * @param Request $request
   * @param array   $orderIds
   *
   * @return string
   */
  public function registerShipments(Request $request, $orderIds) {
    $orderIds     = $this->getOrderIds($request, $orderIds);
    $orderIds     = $this->getOpenOrderIds($orderIds);
    $shipmentDate = date('Y-m-d');

    foreach ($orderIds as $orderId) {
      $order = $this->orderRepository->findOrderById($orderId);

      // gathering required data for registering the shipment

      /** @var Address $address */
      $address = $order->deliveryAddress;

      $receiverFirstName  = $address->firstName;
      $receiverLastName   = $address->lastName;
      $receiverStreet     = $address->street;
      $receiverNo         = $address->houseNumber;
      $receiverPostalCode = $address->postalCode;
      $receiverTown       = $address->town;
      $receiverCountry    = $address->country->name; // or: $address->country->isoCode2

      // reads sender data from plugin config. this is going to be changed in the future to retrieve data from backend ui settings
      $senderName       = $this->config->get('ShippingTutorial.senderName', 'plentymarkets GmbH - Timo Zenke');
      $senderStreet     = $this->config->get('ShippingTutorial.senderStreet', 'Bürgermeister-Brunner-Str.');
      $senderNo         = $this->config->get('ShippingTutorial.senderNo', '15');
      $senderPostalCode = $this->config->get('ShippingTutorial.senderPostalCode', '34117');
      $senderTown       = $this->config->get('ShippingTutorial.senderTown', 'Kassel');
      $senderCountryID  = $this->config->get('ShippingTutorial.senderCountry', '0');
      $senderCountry    = ($senderCountryID == 0 ? 'Germany' : 'Austria');

      // gets order shipping packages from current order
      $packages = $this->orderShippingPackage->listOrderShippingPackages($order->id);

      // iterating through packages
      foreach ($packages as $package) {
        // weight
        $weight = $package->weight;

        // determine packageType
        $packageType = $this->shippingPackageTypeRepositoryContract->findShippingPackageTypeById($package->packageId);

        $this->getLogger(__METHOD__)
            ->error('package data', ['package' => $packageType, 'packageId' => $package->packageId]);

        // package dimensions
        list ($length, $width, $height) = $this->getPackageDimensions($packageType);

        try {
          // check wether we are in test or productive mode, use different login or connection data
          $mode = $this->config->get('ShippingTutorial.mode', '0');

          // shipping service providers API should be used here
          $response = [
              'labelUrl'       => 'http://www.dhl.com/content/dam/downloads/g0/express/customs_regulations_china/waybill_sample.pdf',
              'shipmentNumber' => '911778899',
              'sequenceNumber' => 1,
              'status'         => 'shipment sucessfully registered'
          ];

          // handles the response
          $shipmentItems = $this->handleAfterRegisterShipment($response['labelUrl'], $response['shipmentNumber'], $package->id);

          // adds result
          $this->createOrderResult[$orderId] = $this->buildResultArray(true, $this->getStatusMessage($response), false, $shipmentItems);

          // saves shipping information
          $this->saveShippingInformation($orderId, $shipmentDate, $shipmentItems);
        } catch (\SoapFault $soapFault) {
          // handle exception
        }
      }
    }

    // return all results to service
    return $this->createOrderResult;
  }

  /**
   * Cancels registered shipment(s)
   *
   * @param Request $request
   * @param array   $orderIds
   *
   * @return array
   */
  public function deleteShipments(Request $request, $orderIds) {
    $orderIds = $this->getOrderIds($request, $orderIds);
    foreach ($orderIds as $orderId) {
      $shippingInformation = $this->shippingInformationRepositoryContract->getShippingInformationByOrderId($orderId);

      if (isset($shippingInformation->additionalData) && is_array($shippingInformation->additionalData)) {
        foreach ($shippingInformation->additionalData as $additionalData) {
          try {
            $shipmentNumber = $additionalData['shipmentNumber'];

            // use the shipping service provider's API here
            $response = '';

            $this->createOrderResult[$orderId] = $this->buildResultArray(true, $this->getStatusMessage($response), false, null);
          } catch (\SoapFault $soapFault) {
            // exception handling
          }
        }

        // resets the shipping information of current order
        $this->shippingInformationRepositoryContract->resetShippingInformation($orderId);
      }
    }

    // return result array
    return $this->createOrderResult;
  }

  /**
   * Retrieves the label file from a given URL and saves it in S3 storage
   *
   * @param
   *            $labelUrl
   * @param
   *            $key
   *
   * @return StorageObject
   */
  private function saveLabelToS3($labelUrl, $key) {
    $output = $this->download($labelUrl);
    $this->getLogger(__METHOD__)->error(
        'save to S3 data: ', [
                               'data'     => $output,
                               'key'      => $key,
                               'labelUrl' => $labelUrl
                           ]
    );

    return $this->storageRepository->uploadObject('ShippingTutorial', $key, base64_decode($output));
  }

  /**
   * Returns the parcel service preset for the given Id.
   *
   * @param int $parcelServicePresetId
   *
   * @return ParcelServicePreset
   */
  private function getParcelServicePreset($parcelServicePresetId) {
    /** @var ParcelServicePresetRepositoryContract $parcelServicePresetRepository */
    $parcelServicePresetRepository = pluginApp(ParcelServicePresetRepositoryContract::class);

    $parcelServicePreset = $parcelServicePresetRepository->getPresetById($parcelServicePresetId);

    if ($parcelServicePreset) {
      return $parcelServicePreset;
    } else {
      return null;
    }
  }

  /**
   * Returns a formatted status message
   *
   * @param array $response
   *
   * @return string
   */
  private function getStatusMessage($response) {
    return 'Code: ' . $response['status']; // should contain error code and descriptive part
  }

  /**
   * Saves the shipping information
   *
   * @param
   *            $orderId
   * @param
   *            $shipmentDate
   * @param
   *            $shipmentItems
   */
  private function saveShippingInformation($orderId, $shipmentDate, $shipmentItems) {
    $transactionIds = array();
    foreach ($shipmentItems as $shipmentItem) {
      $transactionIds[] = $shipmentItem['shipmentNumber'];
    }

    $shipmentAt     = date(\DateTime::W3C, strtotime($shipmentDate));
    $registrationAt = date(\DateTime::W3C);

    $data = [
        'orderId'                 => $orderId,
        'transactionId'           => implode(',', $transactionIds),
        'shippingServiceProvider' => 'ShippingTutorial',
        'shippingStatus'          => 'registered',
        'shippingCosts'           => 0.00,
        'additionalData'          => $shipmentItems,
        'registrationAt'          => $registrationAt,
        'shipmentAt'              => $shipmentAt
    ];
    $this->shippingInformationRepositoryContract->saveShippingInformation($data);
  }

  /**
   * Returns all order ids with shipping status 'open'
   *
   * @param array $orderIds
   *
   * @return array
   */
  private function getOpenOrderIds($orderIds) {
    $openOrderIds = array();
    foreach ($orderIds as $orderId) {
      $shippingInformation = $this->shippingInformationRepositoryContract->getShippingInformationByOrderId($orderId);
      if ($shippingInformation->shippingStatus == null || $shippingInformation->shippingStatus == 'open') {
        $openOrderIds[] = $orderId;
      }
    }

    return $openOrderIds;
  }

  /**
   * Returns an array in the structure demanded by plenty service
   *
   * @param bool   $success
   * @param string $statusMessage
   * @param bool   $newShippingPackage
   * @param array  $shipmentItems
   *
   * @return array
   */
  private function buildResultArray($success = false, $statusMessage = '', $newShippingPackage = false, $shipmentItems = []) {
    return [
        'success'          => $success,
        'message'          => $statusMessage,
        'newPackagenumber' => $newShippingPackage,
        'packages'         => $shipmentItems
    ];
  }

  /**
   * Returns shipment array
   *
   * @param string $labelUrl
   * @param string $shipmentNumber
   *
   * @return array
   */
  private function buildShipmentItems($labelUrl, $shipmentNumber) {
    return [
        'labelUrl'       => $labelUrl,
        'shipmentNumber' => $shipmentNumber
    ];
  }

  /**
   * Returns package info
   *
   * @param string $packageNumber
   * @param string $labelUrl
   *
   * @return array
   */
  private function buildPackageInfo($packageNumber, $labelUrl) {
    return [
        'packageNumber' => $packageNumber,
        'label'         => $labelUrl
    ];
  }

  /**
   * Returns all order ids from request object
   *
   * @param Request $request
   * @param
   *            $orderIds
   *
   * @return array
   */
  private function getOrderIds(Request $request, $orderIds) {
    if (is_numeric($orderIds)) {
      $orderIds = array(
          $orderIds
      );
    } else if (!is_array($orderIds)) {
      $orderIds = $request->get('orderIds');
    }

    return $orderIds;
  }

  /**
   * Returns the package dimensions by package type
   *
   * @param
   *            $packageType
   *
   * @return array
   */
  private function getPackageDimensions($packageType): array {
    if ($packageType->length > 0 && $packageType->width > 0 && $packageType->height > 0) {
      $length = $packageType->length;
      $width  = $packageType->width;
      $height = $packageType->height;
    } else {
      $length = null;
      $width  = null;
      $height = null;
    }

    return array(
        $length,
        $width,
        $height
    );
  }

  /**
   * Handling of response values, fires S3 storage and updates order shipping package
   *
   * @param string $labelUrl
   * @param string $shipmentNumber
   * @param string $sequenceNumber
   *
   * @return array
   */
  private function handleAfterRegisterShipment($labelUrl, $shipmentNumber, $sequenceNumber) {
    $shipmentItems = array();

    $storageObject = $this->saveLabelToS3($labelUrl, $shipmentNumber . '.pdf');
    $this->getLogger(__FUNCTION__)->error(
        'storage data: ', [
                            'storage' => $storageObject
                        ]
    );
    $shipmentItems[] = $this->buildShipmentItems($labelUrl, $shipmentNumber);

    $this->orderShippingPackage->updateOrderShippingPackage($sequenceNumber, $this->buildPackageInfo($shipmentNumber, $storageObject->key));

    return $shipmentItems;
  }

  /**
   *
   * @param string $fileUrl
   *
   * @return bool|string
   */
  private function download(string $fileUrl) {
    $headers = [];

    $this->getLogger(__METHOD__)
        ->error('call to download', ['url' => $fileUrl]);

    $response = $this->library->call(
        'ShippingTutorial::guzzle',
        [
            'method'    => 'get',
            'arguments' => [
                $fileUrl, [
                    'headers' => $headers
                ]
            ]
        ]
    );

    $this->getLogger(__METHOD__)
        ->error('download finished', ['downloaded' => json_encode($response)]);

    return "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ZG9iagoxOSAwIG9iago8PC9UeXBlIC9YT2JqZWN0IC9TdWJ0eXBlIC9JbWFnZSAvV2lkdGggMjY4IC9IZWlnaHQgNjAgL0JpdHNQZXJDb21wb25lbnQgOCAvQ29sb3JTcGFjZSAvRGV2aWNlUkdCIC9NYXNrIDM1IDAgUgovTGVuZ3RoIDM2IDAgUgovRmlsdGVyIC9GbGF0ZURlY29kZSA+PnN0cmVhbQp4nO3BAQEAAACCIP+vbkhAAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMCvAbxwAAEKZW5kc3RyZWFtCmVuZG9iagoyMCAwIG9iagpbL1BhdHRlcm4gL0RldmljZVJHQiBdCmVuZG9iagoyMSAwIG9iago8PC9UeXBlIC9FeHRHU3RhdGUgL1NBIHRydWUgL1NNIDAuMDIgL2NhIDEgL0NBIDEgL0FJUyBmYWxzZSAvU01hc2sgL05vbmUgPj5lbmRvYmoKMjIgMCBvYmoKPDwvVHlwZSAvRm9udCAvU3VidHlwZSAvVHlwZTAgL0Jhc2VGb250IC9OaW1idXNTYW5MLUJvbGQgL0VuY29kaW5nIC9JZGVudGl0eS1IIC9EZXNjZW5kYW50Rm9udHMgWzM3IDAgUgpdCi9Ub1VuaWNvZGUgMzggMCBSCj4+ZW5kb2JqCjIzIDAgb2JqCjw8L1R5cGUgL0ZvbnQgL1N1YnR5cGUgL1R5cGUwIC9CYXNlRm9udCAvTmltYnVzU2FuTC1SZWd1IC9FbmNvZGluZyAvSWRlbnRpdHktSCAvRGVzY2VuZGFudEZvbnRzIFszOSAwIFIKXQovVG9Vbmljb2RlIDQwIDAgUgo+PmVuZG9iagoyNCAwIG9iago8PC9UeXBlIC9YT2JqZWN0IC9TdWJ0eXBlIC9JbWFnZSAvV2lkdGggMjk5IC9IZWlnaHQgNDggL0JpdHNQZXJDb21wb25lbnQgOCAvQ29sb3JTcGFjZSAvRGV2aWNlUkdCIC9TTWFzayA0MSAwIFIKL0xlbmd0aCA0MiAwIFIKL0ZpbHRlciAvRmxhdGVEZWNvZGUgPj5zdHJlYW0KeJztXVuS2yAQzCVSW04kQEiyt/yRnz3AniL3v0oAOV7ZqwFkT4OQ6eLbWMw0M8wDfvzYKLSUvVRFDy3UIMTz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aXR5LVVDUyBkZWYKL0NNYXBUeXBlIDIgZGVmCjEgYmVnaW5jb2Rlc3BhY2VyYW5nZQo8MDAwMD4gPEZGRkY+CmVuZGNvZGVzcGFjZXJhbmdlCjIgYmVnaW5iZnJhbmdlCjwwMDAwPiA8MDAwMD4gPDAwMDA+CjwwMDAxPiA8MDAyMz4gWzwwMDUzPiA8MDA2OD4gPDAwNjk+IDwwMDcwPiA8MDAyMD4gPDAwNTQ+IDwwMDZGPiA8MDAzQT4gPDAwNjU+IDwwMDYyPiA8MDA2MT4gPDAwNzM+IDwwMDc0PiA8MDA2RT4gPDAwNjM+IDwwMDZEPiA8MDA2ND4gPDAwNDQ+IDwwMDQ1PiA8MDAzMT4gPDAwMzU+IDwwMDM2PiA8MDAzMD4gPDAwMzM+IDwwMDM3PiA8MDA0Mz4gPDAwNzI+IDwwMDY2PiA8MDAzMj4gPDAwNTc+IDwwMDc5PiA8MDA1MD4gPDAwMjM+IDwwMDc1PiA8MDA2Qz4gXQplbmRiZnJhbmdlCmVuZGNtYXAKQ01hcE5hbWUgY3VycmVudGRpY3QgL0NNYXAgZGVmaW5lcmVzb3VyY2UgcG9wCmVuZAplbmQKCmVuZHN0cmVhbQplbmRvYmoKMjkgMCBvYmoKPDwvVHlwZSAvRm9udCAvU3VidHlwZSAvQ0lERm9udFR5cGUyIC9CYXNlRm9udCAvTmltYnVzU2FuTC1SZWd1IC9DSURTeXN0ZW1JbmZvIDw8L1JlZ2lzdHJ5IChBZG9iZSkvT3JkZXJpbmcgKElkZW50aXR5KS9TdXBwbGVtZW50IDAgPj4vRm9udERlc2NyaXB0b3IgNDggMCBSCi9DSURUb0dJRE1hcCAvSWRlbnRpdHkgL1cgWzAgWzI3NiA3MTYgNTUyIDgyNiA1NTIgNTUyIDMzMCA1NTIgNTUyIDI3NiA1NTIgNTUyIDU1MiA1NTIgNDk2IDI3NiA2MDYgNTUyIDU1MiA1NTIgNTUyIDU1MiA3NzIgMjIwIDc3MiA0OTYgNjYyIDcxNiA2NjIgNjYyIDU1MiA1NTIgNTUyIDY2MiA1NTIgNDk2IDU1MiA3MTYgMzMwIDMzMCA3MTYgNTUyIDY2MiAzMzAgXQpdCj4+ZW5kb2JqCjMwIDAgb2JqCjw8L0xlbmd0aCA2NjUgPj5zdHJlYW0KL0NJREluaXQgL1Byb2NTZXQgZmluZHJlc291cmNlIGJlZ2luCjEyIGRpY3QgYmVnaW4KYmVnaW5jbWFwCi9DSURTeXN0ZW1JbmZvIDw8IC9SZWdpc3RyeSAoQWRvYmUpIC9PcmRlcmluZyAoVUNTKSAvU3VwcGxlbWVudCAwID4+IGRlZgovQ01hcE5hbWUgL0Fkb2JlLUlkZW50aXR5LVVDUyBkZWYKL0NNYXBUeXBlIDIgZGVmCjEgYmVnaW5jb2Rlc3BhY2VyYW5nZQo8MDAwMD4gPEZGRkY+CmVuZGNvZGVzcGFjZXJhbmdlCjIgYmVnaW5iZnJhbmdlCjwwMDAwPiA8MDAwMD4gPDAwMDA+CjwwMDAxPiA8MDAyQj4gWzwwMDQ4PiA8MDA2Rj4gPDAwNkQ+IDwwMDYyPiA8MDA3NT4gPDAwNzI+IDwwMDY3PiA8MDA2NT4gPDAwMjA+IDwwMDRDPiA8MDA2MT4gPDAwNkU+IDwwMDY0PiA8MDA3Mz4gPDAwNzQ+IDwwMERGPiA8MDAzOD4gPDAwMzY+IDwwMDMxPiA8MDAzND4gPDAwMzA+IDwwMDRGPiA8MDA2Qz4gPDAwNDc+IDwwMDc5PiA8MDA0NT4gPDAwNTI+IDwwMDQxPiA8MDA0Qj4gPDAwMzI+IDwwMDM1PiA8MDAzMz4gPDAwNDI+IDwwMEZDPiA8MDA2Mz4gPDAwNjg+IDwwMDQzPiA8MDAyOD4gPDAwMjk+IDwwMDQ0PiA8MDAzNz4gPDAwNTM+IDwwMDJEPiBdCmVuZGJmcmFuZ2UKZW5kY21hcApDTWFwTmFtZSBjdXJyZW50ZGljdCAvQ01hcCBkZWZpbmVyZXNvdXJjZSBwb3AKZW5kCmVuZAoKZW5kc3RyZWFtCmVuZG9iagozMSAwIG9iago8PC9UeXBlIC9YT2JqZWN0IC9TdWJ0eXBlIC9JbWFnZSAvV2lkdGggMjk5IC9IZWlnaHQgNDggL0JpdHNQZXJDb21wb25lbnQgOCAvQ29sb3JTcGFjZSAvRGV2aWNlR3JheSAvTGVuZ3RoIDQ5IDAgUgovRmlsdGVyIC9GbGF0ZURlY29kZSA+PnN0cmVhbQp4nOWbe1AVVRzH997r5XERcRAIhWlITMWEmnyAko8E1DKYssyi1GFszHK0rDRfgAZOaDOi9BgfNfQAqj/UwceY/SGCoEkJGUIlFKGM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7fn5z382sDlw/vPnw/Y8zGds+M98+DyqYlTFqIpRFaMqRlWMqhgmKgDMCrxuCmVuZHN0cmVhbQplbmRvYmoKMzQgMCBvYmoKNjkgZW5kb2JqCjM1IDAgb2JqCjw8L1R5cGUgL1hPYmplY3QgL1N1YnR5cGUgL0ltYWdlIC9XaWR0aCAyNjggL0hlaWdodCA2MCAvSW1hZ2VNYXNrIHRydWUgL0RlY29kZSBbMSAwIF0KL0xlbmd0aCA1MSAwIFIKL0ZpbHRlciAvRmxhdGVEZWNvZGUgPj5zdHJlYW0KeJztyrEJACAMBEDBNuAqQlrB1YVvA1kl8K2Fe8hffRw4YX5nTRaAzL4zypzMhjWiqKGhoaGhofHJeGN9u7kKZW5kc3RyZWFtCmVuZG9iagozNiAwIG9iago2OSBlbmRvYmoKMzcgMCBvYmoKPDwvVHlwZSAvRm9udCAvU3VidHlwZSAvQ0lERm9udFR5cGUyIC9CYXNlRm9udCAvTmltYnVzU2FuTC1Cb2xkIC9DSURTeXN0ZW1JbmZvIDw8L1JlZ2lzdHJ5IChBZG9iZSkvT3JkZXJpbmcgKElkZW50aXR5KS9TdXBwbGVtZW50IDAgPj4vRm9udERlc2NyaXB0b3IgNTIgMCBSCi9DSURUb0dJRE1hcCAvSWRlbnRpdHkgL1cgWzAgWzQ5NiA2NjIgNjA2IDI3NiA2MDYgMjc2IDYwNiA2MDYgMzMwIDU1MiA2MDYgNTUyIDU1MiAzMzAgNjA2IDU1MiA4ODIgNjA2IDcxNiA2NjIgNTUyIDU1MiA1NTIgNTUyIDU1MiA1NTIgNzE2IDM4NiA1NTIgMzMwIDkzNiA1NTIgNjYyIDU1MiA2MDYgMjc2IF0KXQo+PmVuZG9iagozOCAwIG9iago8PC9MZW5ndGggNjA5ID4+c3RyZWFtCi9DSURJbml0IC9Qcm9jU2V0IGZpbmRyZXNvdXJjZSBiZWdpbgoxMiBkaWN0IGJlZ2luCmJlZ2luY21hcAovQ0lEU3lzdGVtSW5mbyA8PCAvUmVnaXN0cnkgKEFkb2JlKSAvT3JkZXJpbmcgKFVDUykgL1N1cHBsZW1lbnQgMCA+PiBkZWYKL0NNYXBOYW1lIC9BZG9iZS1JZGVudGl0eS1VQ1MgZGVmCi9DTWFwVHlwZSAyIGRlZgoxIGJlZ2luY29kZXNwYWNlcmFuZ2UKPDAwMDA+IDxGRkZGPgplbmRjb2Rlc3BhY2VyYW5nZQoyIGJlZ2luYmZyYW5nZQo8MDAwMD4gPDAwMDA+IDwwMDAwPgo8MDAwMT4gPDAwMjM+IFs8MDA1Mz4gPDAwNjg+IDwwMDY5PiA8MDA3MD4gPDAwMjA+IDwwMDU0PiA8MDA2Rj4gPDAwM0E+IDwwMDY1PiA8MDA2Mj4gPDAwNjE+IDwwMDczPiA8MDA3ND4gPDAwNkU+IDwwMDYzPiA8MDA2RD4gPDAwNjQ+IDwwMDQ0PiA8MDA0NT4gPDAwMzE+IDwwMDM1PiA8MDAzNj4gPDAwMzA+IDwwMDMzPiA8MDAzNz4gPDAwNDM+IDwwMDcyPiA8MDAzMj4gPDAwNjY+IDwwMDU3PiA8MDA3OT4gPDAwNTA+IDwwMDIzPiA8MDA3NT4gPDAwNkM+IF0KZW5kYmZyYW5nZQplbmRjbWFwCkNNYXBOYW1lIGN1cnJlbnRkaWN0IC9DTWFwIGRlZmluZXJlc291cmNlIHBvcAplbmQKZW5kCgplbmRzdHJlYW0KZW5kb2JqCjM5IDAgb2JqCjw8L1R5cGUgL0ZvbnQgL1N1YnR5cGUgL0NJREZvbnRUeXBlMiAvQmFzZUZvbnQgL05pbWJ1c1NhbkwtUmVndSAvQ0lEU3lzdGVtSW5mbyA8PC9SZWdpc3RyeSAoQWRvYmUpL09yZGVyaW5nIChJZGVudGl0eSkvU3VwcGxlbWVudCAwID4+L0ZvbnREZXNjcmlwdG9yIDUzIDAgUgovQ0lEVG9HSURNYXAgL0lkZW50aXR5IC9XIFswIFsyNzYgNzE2IDU1MiA4MjYgNTUyIDU1MiAzMzAgNTUyIDU1MiAyNzYgNTUyIDU1MiA1NTIgNTUyIDQ5NiAyNzYgNjA2IDU1MiA1NTIgNTUyIDU1MiA1NTIgNzcyIDIyMCA3NzIgNDk2IDY2MiA3MTYgNjYyIDY2MiA1NTIgNTUyIDU1MiA2NjIgNTUyIDQ5NiA1NTIgNzE2IDMzMCAzMzAgNzE2IDU1MiA2NjIgMzMwIF0KXQo+PmVuZG9iago0MCAwIG9iago8PC9MZW5ndGggNjY1ID4+c3RyZWFtCi9DSURJbml0IC9Qcm9jU2V0IGZpbmRyZXNvdXJjZSBiZWdpbgoxMiBkaWN0IGJlZ2lu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JioAzAq8bgplbmRzdHJlYW0KZW5kb2JqCjQ0IDAgb2JqCjY5IGVuZG9iago0NSAwIG9iago8PC9UeXBlIC9YT2JqZWN0IC9TdWJ0eXBlIC9JbWFnZSAvV2lkdGggMjY4IC9IZWlnaHQgNjAgL0ltYWdlTWFzayB0cnVlIC9EZWNvZGUgWzEgMCBdCi9MZW5ndGggNTYgMCBSCi9GaWx0ZXIgL0ZsYXRlRGVjb2RlID4+c3RyZWFtCnic+8x/+MAZHuM/Bh8MPn84fPjw+fPM9ufPfACK8Jz/zGzDf+bD51EVoypGVYyqGFUxqmJUxTBRAQAqc5OlCmVuZHN0cmVhbQplbmRvYmoKNDYgMCBvYmoKNjkgZW5kb2JqCjQ3IDAgb2JqCjw8L1R5cGUgL0ZvbnREZXNjcmlwdG9yIC9Gb250TmFtZSAvUUZCQUFBK05pbWJ1c1NhbkwtQm9sZCAvRmxhZ3MgNCAvRm9udEJCb3ggWy0xNzMgLTMwNyAxMDk3IDk3OSBdCi9JdGFsaWNBbmdsZSAwIC9Bc2NlbnQgOTc5IC9EZXNjZW50IC0zMDcgL0NhcEhlaWdodCA5NzkgL1N0ZW1WIDY5IC9Gb250RmlsZTIgNTcgMCBSCj4+ZW5kb2JqCjQ4IDAgb2JqCjw8L1R5cGUgL0ZvbnREZXNjcmlwdG9yIC9Gb250TmFtZSAvUUtCQUFBK05pbWJ1c1NhbkwtUmVndSAvRmxhZ3MgNCAvRm9udEJCb3ggWy0xNzQgLTI4NSAxMDIyIDk1MyBdCi9JdGFsaWNBbmdsZSAwIC9Bc2NlbnQgOTUzIC9EZXNjZW50IC0yODUgL0NhcEhlaWdodCA5NTMgL1N0ZW1WIDUwIC9Gb250RmlsZTIgNTggMCBSCj4+ZW5kb2JqCjQ5IDAgb2JqCjE2ODAgZW5kb2JqCjUwIDAgb2JqCjYyIGVuZG9iago1MSAwIG9iago2MiBlbmRvYmoKNTIgMCBvYmoKPDwvVHlwZSAvRm9udERlc2NyaXB0b3IgL0ZvbnROYW1lIC9RRkJBQUErTmltYnVzU2FuTC1Cb2xkIC9GbGFncyA0IC9Gb250QkJveCBbLTE3MyAtMzA3IDEwOTcgOTc5IF0KL0l0YWxpY0FuZ2xlIDAgL0FzY2VudCA5NzkgL0Rlc2NlbnQgLTMwNyAvQ2FwSGVp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dGlvbkRhdGUgKEQ6MjAxOTAyMTkwODQyNDkpCj4+CmVuZG9iago2NiAwIG9iago8PAovVHlwZSAvQ2F0YWxvZwovUGFnZXMgMSAwIFIKPj4KZW5kb2JqCnhyZWYKMCA2NwowMDAwMDAwMDAwIDY1NTM1IGYgCjAwMDAwMDA2MDMgMDAwMDAgbiAKMDAwMDA0NzU0MyAwMDAwMCBuIAowMDAwMDAwMDA5IDAwMDAwIG4gCjAwMDAwMDAxNzMgMDAwMDAgbiAKMDAwMDAwMDMwNiAwMDAwMCBuIAowMDAwMDAwNDcwIDAwMDAwIG4gCjAwMDAwMDA2OTYgMDAwMDAgbiAKMDAwMDAwMTM1NyAwMDAwMCBuIAowMDAwMDAxNjc4IDAwMDAwIG4gCjAwMDAwMDEwMjYgMDAwMDAgbiAKMDAwMDAwMTk5OSAwMDAwMCBuIAowMDAwMDA5MTc3IDAwMDAwIG4gCjAwMDAwMTYzNTUgMDAwMDAgbiAKMDAwMDAxNjM5NCAwMDAwMCBuIAowMDAwMDE2NDg1IDAwMDAwIG4gCjAwMDAwMTY2MjcgMDAwMDAgbiAKMDAwMDAxNjc2OSAwMDAwMCBuIAowMDAwMDE4NjUzIDAwMDAwIG4gCjAwMDAwMTg5MDUgMDAwMDAgbiAKMDAwMDAxOTE1NyAwMDAwMCBuIAowMDAwMDE5MTk2IDAwMDAwIG4gCjAwMDAwMTkyODcgMDAwMDAgbiAKMDAwMDAxOTQyOSAwMDAwMCBuIAowMDAwMDE5NTcxIDAwMDAwIG4gCjAwMDAwMjE0NTUgMDAwMDAgbiAKMDAwMDAyMTcwNyAwMDAwMCBuIAowMDAwMDIxOTU5IDAwMDAwIG4gCjAwMDAwMjIzMTIgMDAwMDAgbiAKMDAwMDAyMjk3MSAwMDAwMCBuIAowMDAwMDIzMzU2IDAwMDAwIG4gCjAwMDAwMjQwNzEgMDAwMDAgbiAKMDAwMDAyNTkyMiAwMDAwMCBuIAowMDAwMDI1OTQzIDAwMDAwIG4gCjAwMDAwMjYxNjMgMDAwMDAgbiAKMDAwMDAyNjE4MiAwMDAwMCBuIAowMDAwMDI2NDAyIDAwMDAwIG4gCjAwMDAwMjY0MjEgMDAwMDAgbiAKMDAwMDAyNjc3NCAwMDAwMCBuIAowMDAwMDI3NDMzIDAwMDAwIG4gCjAwMDAwMjc4MTggMDAwMDAgbiAKMDAwMDAyODUzMyAwMDAwMCBuIAowMDAwMDMwMzg0IDAwMDAwIG4gCjAwMDAwMzA0MDUgMDAwMDAgbiAKMDAwMDAzMDYyNSAwMDAwMCBuIAowMDAwMDMwNjQ0IDAwMDAwIG4gCjAwMDAwMzA4NjQgMDAwMDAgbiAKMDAwMDAzMDg4MyAwMDAwMCBuIAowMDAwMDMxMDg0IDAwMDAwIG4gCjAwMDAwMzEyODUgMDAwMDAgbiAKMDAwMDAzMTMwNiAwMDAwMCBuIAowMDAwMDMxMzI1IDAwMDAwIG4gCjAwMDAwMzEzNDQgMDAwMDAgbiAKMDAwMDAzMTU0NSAwMDAwMCBuIAowMDAwMDMxNzQ2IDAwMDAwIG4gCjAwMDAwMzE3NjcgMDAwMDAgbiAKMDAwMDAzMTc4NiAwMDAwMCBuIAowMDAwMDMxODA1IDAwMDAwIG4gCjAwMDAwMzUyOTcgMDAwMDAgbiAKMDAwMDAzOTYzMSAwMDAwMCBuIAowMDAwMDQzMTI1IDAwMDAwIG4gCjAwMDAwNDc0NTkgMDAwMDAgbiAKMDAwMDA0NzQ4MCAwMDAwMCBuIAowMDAwMDQ3NTAxIDAwMDAwIG4gCjAwMDAwNDc1MjIgMDAwMDAgbiAKMDAwMDA0NzY2MiAwMDAwMCBuIAowMDAwMDQ3NzM4IDAwMDAwIG4gCnRyYWlsZXIKPDwKL1NpemUgNjcKL1Jvb3QgNjYgMCBSCi9JbmZvIDY1IDAgUgo+PgpzdGFydHhyZWYKNDc3ODgKJSVFT0YK";
  }
}
