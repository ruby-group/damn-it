<?php

/**
 * @file
 * Contains \Drupal\Core\Config\StorableConfigBase.
 */

namespace Drupal\Core\Config;

use Drupal\Component\Utility\String;
use Drupal\Core\Config\Schema\Ignore;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\Core\TypedData\Type\FloatInterface;
use Drupal\Core\TypedData\Type\IntegerInterface;
use Drupal\Core\Config\Schema\Undefined;

/**
 * Provides a base class for configuration objects with storage support.
 *
 * Encapsulates all capabilities needed for configuration handling for a
 * specific configuration object, including storage and data type casting.
 *
 * The default implementation in \Drupal\Core\Config\Config adds support for
 * runtime overrides. Extend from StorableConfigBase directly to manage
 * configuration with a storage backend that does not support overrides.
 *
 * @see \Drupal\Core\Config\Config
 */
abstract class StorableConfigBase extends ConfigBase {

  /**
   * The storage used to load and save this configuration object.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $storage;

  /**
   * The config schema wrapper object for this configuration object.
   *
   * @var \Drupal\Core\Config\Schema\Element
   */
  protected $schemaWrapper;

  /**
   * The typed config manager.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected $typedConfigManager;

  /**
   * Whether the configuration object is new or has been saved to the storage.
   *
   * @var bool
   */
  protected $isNew = TRUE;

  /**
   * The data of the configuration object.
   *
   * @var array
   */
  protected $originalData = array();

  /**
   * Saves the configuration object.
   *
   * @return \Drupal\Core\Config\Config
   *   The configuration object.
   */
  abstract public function save();

  /**
   * Deletes the configuration object.
   *
   * @return \Drupal\Core\Config\Config
   *   The configuration object.
   */
  abstract public function delete();

  /**
   * Initializes a configuration object with pre-loaded data.
   *
   * @param array $data
   *   Array of loaded data for this configuration object.
   *
   * @return $this
   *   The configuration object.
   */
  public function initWithData(array $data) {
    $this->isNew = FALSE;
    $this->setData($data, FALSE);
    $this->originalData = $this->data;
    return $this;
  }

  /**
   * Returns whether this configuration object is new.
   *
   * @return bool
   *   TRUE if this configuration object does not exist in storage.
   */
  public function isNew() {
    return $this->isNew;
  }

  /**
   * Retrieves the storage used to load and save this configuration object.
   *
   * @return \Drupal\Core\Config\StorageInterface
   *   The configuration storage object.
   */
  public function getStorage() {
    return $this->storage;
  }

  /**
   * Gets the schema wrapper for the whole configuration object.
   *
   * The schema wrapper is dependent on the configuration name and the whole
   * data structure, so if the name or the data changes in any way, the wrapper
   * should be reset.
   *
   * @return \Drupal\Core\Config\Schema\Element
   */
  protected function getSchemaWrapper() {
    if (!isset($this->schemaWrapper)) {
      $definition = $this->typedConfigManager->getDefinition($this->name);
      $data_definition = $this->typedConfigManager->buildDataDefinition($definition, $this->data);
      $this->schemaWrapper = $this->typedConfigManager->create($data_definition, $this->data);
    }
    return $this->schemaWrapper;
  }

  /**
   * Validate the values are allowed data types.
   *
   * @param string $key
   *   A string that maps to a key within the configuration data.
   * @param string $value
   *   Value to associate with the key.
   *
   * @return null
   *
   * @throws \Drupal\Core\Config\UnsupportedDataTypeConfigException
   *   If the value is unsupported in configuration.
   */
  protected function validateValue($key, $value) {
    // Minimal validation. Should not try to serialize resources or non-arrays.
    if (is_array($value)) {
      foreach ($value as $nested_value_key => $nested_value) {
        $this->validateValue($key . '.' . $nested_value_key, $nested_value);
      }
    }
    elseif ($value !== NULL && !is_scalar($value)) {
      throw new UnsupportedDataTypeConfigException(String::format('Invalid data type for config element @name:@key', array(
        '@name' => $this->getName(),
        '@key' => $key,
      )));
    }
  }

  /**
   * Casts the value to correct data type using the configuration schema.
   *
   * @param string $key
   *   A string that maps to a key within the configuration data.
   * @param string $value
   *   Value to associate with the key.
   *
   * @return mixed
   *   The value cast to the type indicated in the schema.
   *
   * @throws \Drupal\Core\Config\UnsupportedDataTypeConfigException
   *   If the value is unsupported in configuration.
   */
  protected function castValue($key, $value) {
    $element = $this->getSchemaWrapper()->get($key);
    // Do not cast value if it is unknown or defined to be ignored.
    if ($element && ($element instanceof Undefined || $element instanceof Ignore)) {
      // Do validate the value (may throw UnsupportedDataTypeConfigException)
      // to ensure unsupported types are not supported in this case either.
      $this->validateValue($key, $value);
      return $value;
    }
    if (is_scalar($value) || $value === NULL) {
      if ($element && $element instanceof PrimitiveInterface) {
        // Special handling for integers and floats since the configuration
        // system is primarily concerned with saving values from the Form API
        // we have to special case the meaning of an empty string for numeric
        // types. In PHP this would be casted to a 0 but for the purposes of
        // configuration we need to treat this as a NULL.
        $empty_value =  $value === '' && ($element instanceof IntegerInterface || $element instanceof FloatInterface);

        if ($value === NULL || $empty_value) {
          $value = NULL;
        }
        else {
          $value = $element->getCastedValue();
        }
      }
    }
    else {
      // Throw exception on any non-scalar or non-array value.
      if (!is_array($value)) {
        throw new UnsupportedDataTypeConfigException(String::format('Invalid data type for config element @name:@key', array(
          '@name' => $this->getName(),
          '@key' => $key,
        )));
      }
      // Recurse into any nested keys.
      foreach ($value as $nested_value_key => $nested_value) {
        $value[$nested_value_key] = $this->castValue($key . '.' . $nested_value_key, $nested_value);
      }
    }
    return $value;
  }

}
