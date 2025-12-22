<?php

namespace App\Services\Trm;

use Exception;

class TrmSoapClient
{
    private const SOAP_URL = 'https://www.superfinanciera.gov.co/SuperfinancieraWebServiceTRM/TCRMServicesWebService/TCRMServicesWebService';

    public function fetch(string $date): array
    {
        $xmlPostString = <<<XML
<Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/">
  <Body>
    <queryTCRM xmlns="http://action.trm.services.generic.action.superfinanciera.nexura.sc.com.co/">
      <tcrmQueryAssociatedDate xmlns="">{$date}</tcrmQueryAssociatedDate>
    </queryTCRM>
  </Body>
</Envelope>
XML;

        $headers = [
            'Content-type: text/xml; charset="utf-8"',
            'Accept: text/xml',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'SOAPAction: ""',
            'Content-length: ' . strlen($xmlPostString),
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_URL, self::SOAP_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlPostString);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        if (curl_errno($ch) || $response === false) {
            $errorMessage = curl_error($ch);
            curl_close($ch);
            throw new Exception('TRM SOAP Error: ' . $errorMessage);
        }

        curl_close($ch);

        $response = str_replace(['soap:', 'ns2:'], '', $response);
        $xml = new \SimpleXMLElement($response);
        $returnNode = $xml->Body->queryTCRMResponse->return;

        if (! $returnNode || ! isset($returnNode->success) || (string) $returnNode->success !== 'true') {
            throw new Exception('TRM SOAP: respuesta no exitosa para fecha ' . $date);
        }

        return [
            'date' => $date,
            'value' => (float) $returnNode->value,
            'unit' => (string) $returnNode->unit,
            'valid_from' => (string) $returnNode->validityFrom,
            'valid_to' => (string) $returnNode->validityTo,
            'id' => (string) $returnNode->id,
        ];
    }
}

