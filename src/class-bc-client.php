<?php
//Bigcommerce\Api\Client doesn't work with v3 Api\Client
// class BCClient is temporary solution for this issue
class BCClient extends Bigcommerce\Api\Client {
 private static function mapCollection($resource, $object) {
  return $object;
 }
  /**
  * Send a post request to create a resource on the specified collection.
  *
  * @param string $path api endpoint
  * @param mixed $object object or XML string to create
  * @return mixed
  */
 public static function createResource($path, $object)
 {
  $api_path = self::$api_path;
  self::$api_path = substr( self::$api_path, 0, -3);
  $result = parent::createResource($path, $object);
  self::$api_path = $api_path;
  return $result;
 }

 /**
  * Send a put request to update the specified resource.
  *
  * @param string $path api endpoint
  * @param mixed $object object or XML string to update
  * @return mixed
  */
 public static function updateResource($path, $object)
 {
  $api_path = self::$api_path;
  self::$api_path = substr( self::$api_path, 0, -3);
  $result = parent::updateResource($path, $object);
  self::$api_path = $api_path;
  return $result;
 }

 /**
  * Send a delete request to remove the specified resource.
  *
  * @param string $path api endpoint
  * @return mixed
  */
 public static function deleteResource($path)
 {
  $api_path = self::$api_path;
  self::$api_path = substr( self::$api_path, 0, -3);
  $result = parent::deleteResource($path);
  self::$api_path = $api_path;
  return $result;
 }
  /**
  * Get a collection result from the specified endpoint.
  *
  * @param string $path api endpoint
  * @param string $resource resource class to map individual items
  * @return mixed array|string mapped collection or XML string if useXml is true
  */
 public static function getCollection($path, $resource = 'Resource')
 {
  $api_path = self::$api_path;
  self::$api_path = substr( self::$api_path, 0, -3);
  $result = parent::getCollection($path, $resource);
  self::$api_path = $api_path;
  return $result;
 }
}




