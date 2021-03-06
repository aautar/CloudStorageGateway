<?php

namespace CloudStorageGateway;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

class S3Driver implements Driver
{
    const HTTP_NOT_FOUND = 404;

    /**
     * @var S3Client
     */
    protected $s3Client;

    /**
     * @var string
     */
    protected $region;

    /**
     * @param string $command
     * @param string $bucket
     * @param string $key
     * @param int lifetime in seconds
     * @return string
     */    
    protected function createPresignedRequest($command, $bucket, $key, $lifetimeSec=300)
    {
        $cmd = $this->s3Client->getCommand($command, [
            'Bucket' => $bucket,
            'Key'    => $key
        ]);

        try {
            $req = $this->s3Client->createPresignedRequest($cmd, "+{$lifetimeSec} seconds");
            return (string)$req->getUri();
        } catch(S3Exception $e) {
            throw new DriverException("S3 client failure", $e->getStatusCode(), $e);
        }
    }

    /**
     *
     * @param string $accessKey
     * @param string $secretKey
     * @param string $region
     */
    public function __construct($accessKey, $secretKey, $region)
    {
        $this->region = $region;
        
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region'  => $region,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ]
        ]);
    }

    /**
     *
     * @param string $bucket
     * @return boolean
     */
    public function isBucketAccessible($bucket)
    {
        try
        {
            $this->s3Client->headBucket([
                'Bucket' => $bucket
            ]);
        }
        catch (S3Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @param string $bucket
     * @param string $key
     * @return ObjectInfo|null
     */
    public function getObjectInfo($bucket, $key)
    {
        $result = [];

        try
        {
            $result = $this->s3Client->headObject([
                'Bucket' => $bucket,
                'Key' => $key
            ]);
        }
        catch (S3Exception $e) {
            if($e->getStatusCode() === self::HTTP_NOT_FOUND) {
                return null;
            }

            throw new DriverException("S3 client failure", $e->getStatusCode(), $e);
        }

        return new ObjectInfo(
            $this->buildObjectURL($bucket, $key),
            $result['ContentType'],
            $result['ContentLength'],
            trim($result['ETag'], '"'),
            new \DateTime($result['LastModified'])
        );
    }

    /**
     * @param string $bucket
     * @param string $key
     * @return string
     */
    public function getObjectAsString($bucket, $key)
    {
        $result = null;

        $reqParams = [
            'Bucket' => $bucket,
            'Key' => $key
        ];

        try {
            $response = $this->s3Client->getObject($reqParams);
            $result = (string)$response['Body'];
        } catch(S3Exception $e) {
            
            if($e->getStatusCode() === self::HTTP_NOT_FOUND) {
                return null;
            }

            throw new DriverException("S3 client failure", $e->getStatusCode(), $e);
        }

        return $result;
    }


    /**
     * @param string $bucket
     * @param string $key
     * @param string $saveToFile
     * @return bool
     */
    public function getObjectAsFile($bucket, $key, $saveToFile)
    {
        $reqParams = [
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $saveToFile
        ];

        try {
            $this->s3Client->getObject($reqParams);
        } catch(S3Exception $e) {

            if($e->getStatusCode() === self::HTTP_NOT_FOUND) {
                return false;
            }

            throw new DriverException("S3 client failure", $e->getStatusCode(), $e);
        }

        return true;
    }

    /**
     * @param string $bucket
     * @param string $key
     * @param int lifetime in seconds
     * @return string
     */
    public function getObjectAuthenticatedURL($bucket, $key, $lifetimeSec=300)
    {
        return $this->createPresignedRequest('GetObject', $bucket, $key, $lifetimeSec);
    }

    /**
     * @param string $bucket
     * @param string $key
     * @param int lifetime in seconds
     * @return string
     */    
    public function getPutObjectPresignedURL($bucket, $key, $lifetimeSec=300)
    {
        return $this->createPresignedRequest('PutObject', $bucket, $key, $lifetimeSec);
    }

    /**
     *
     * @param string $bucket
     * @param string $key
     * @returns string
     */
    public function buildObjectURL($bucket, $key)
    {
        return "https://{$this->region}.amazonaws.com/{$bucket}/{$key}";
    }

    /**
     * @param string $bucket
     * @param string $key
     * @return bool
     */
    public function deleteObject($bucket, $key)
    {
        try
        {
            $this->s3Client->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $key
                ]);
        } catch(S3Exception $e) {
            throw new DriverException("S3 client failure", $e->getStatusCode(), $e);
        }

        return true;
    }

    /**
     * @param string $fromBucket
     * @param string $fromKey
     * @param string $toBucket
     * @param string $toKey
     * @return bool
     */
    public function copyObject($fromBucket, $fromKey, $toBucket, $toKey)
    {
        try
        {
            $this->s3Client->copyObject([
                'CopySource' => urlencode($fromBucket . "/" . $fromKey),
                'Bucket' => $toBucket,
                'Key' => $toKey
            ]);
        } catch(S3Exception $e) {
            throw new DriverException("S3 client failure", $e->getStatusCode(), $e);
        }

        return true;
    }

    /**
     *
     * @param string $data
     * @param string $bucket
     * @param string $key
     * @param string $contentType
     * @param bool $isPublic
     * @return bool
     */
    public function putObjectFromString($data, $bucket, $key, $contentType=null, $isPublic=false)
    {
        $reqParams = [
            'Body' => $data,
            'Bucket' => $bucket,
            'Key' => $key,
            'ACL' => 'private'
        ];

        if($contentType) {
            $reqParams['ContentType'] = $contentType;
        }

        if($isPublic) {
            $reqParams['ACL'] = 'public-read';
        }

        try {
            $this->s3Client->putObject($reqParams);
        } catch(S3Exception $e) {
            throw new DriverException("S3 client failure", $e->getStatusCode(), $e);
        }

        return true;
    }

    /**
     *
     * @param string $filepath
     * @param string $bucket
     * @param string $key
     * @param string $contentType
     * @param bool $isPublic
     * @return bool
     */
    public function putObjectFromFile($filepath, $bucket, $key, $contentType=null, $isPublic=false)
    {
        $reqParams = [
            'Bucket' => $bucket,
            'Key' => $key,
            'SourceFile' => $filepath,
            'ACL' => 'private'
        ];

        if($contentType) {
            $reqParams['ContentType'] = $contentType;
        }

        if($isPublic) {
            $reqParams['ACL'] = 'public-read';
        }

        try {
            $this->s3Client->putObject($reqParams);
        } catch(S3Exception $e) {
            throw new DriverException("S3 client failure", $e->getStatusCode(), $e);
        }

        return true;
    }
}
