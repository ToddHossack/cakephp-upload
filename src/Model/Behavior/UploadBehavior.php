<?php
namespace Josegonzalez\Upload\Model\Behavior;

use ArrayObject;
use Cake\Collection\Collection;
use Cake\Database\Type;
use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\Utility\Hash;
use Exception;
use Josegonzalez\Upload\File\Path\DefaultProcessor;
use Josegonzalez\Upload\File\Transformer\DefaultTransformer;
use Josegonzalez\Upload\File\Writer\DefaultWriter;
use UnexpectedValueException;
use RuntimeException;

class UploadBehavior extends Behavior
{

    /**
     * Initialize hook
     *
     * @param array $config The config for this behavior.
     * @return void
     */
    public function initialize(array $config)
    {
        $configs = [];
        foreach ($config as $field => $settings) {
            if (is_int($field)) {
                $configs[$settings] = [];
            } else {
                $configs[$field] = $settings;
            }
        }

        $this->_config = [];
        $this->config($configs);

        Type::map('upload.file', 'Josegonzalez\Upload\Database\Type\FileType');
        $schema = $this->_table->schema();
        foreach (array_keys($this->config()) as $field) {
            $schema->columnType($field, 'upload.file');
        }
        $this->_table->schema($schema);
    }

    /**
     * Modifies the data being marshalled to ensure invalid upload data is not inserted
     *
     * @param \Cake\Event\Event $event an event instance
     * @param \ArrayObject $data data being marshalled
     * @param \ArrayObject $options options for the current event
     * @return void
     */
    public function beforeMarshal(Event $event, ArrayObject $data, ArrayObject $options)
    {
        $validator = $this->_table->validator();
        $dataArray = $data->getArrayCopy();
        foreach (array_keys($this->config()) as $field) {
            if (!$validator->isEmptyAllowed($field, false)) {
                continue;
            }
            if (Hash::get($dataArray, $field . '.error') !== UPLOAD_ERR_NO_FILE) {
                continue;
            }
            unset($data[$field]);
        }
    }

    /**
     * Modifies the entity before it is saved so that uploaded file data is persisted
     * in the database too. On new entities, it defers fields to afterSave if they use primaryKey in the path.
     *
     * @param \Cake\Event\Event $event The beforeSave event that was fired
     * @param \Cake\ORM\Entity $entity The entity that is going to be saved
     * @param \ArrayObject $options the options passed to the save method
     * @return void|false
     */
    public function beforeSave(Event $event, Entity $entity, ArrayObject $options)
    {
        $fields = ($entity->isNew()) ? $this->deferFields($entity) : $this->config();
        return $this->writeFiles($fields,$event,$entity,$options);
    }   
   
    /**
     * Modifies the entity after it is saved with uploaded file data
     *
     * @param \Cake\Event\Event $event The afterSave event that was fired
     * @param \Cake\ORM\Entity $entity The entity that was saved
     * @param \ArrayObject $options the options passed to the save method
     * @return void|false
     */
    public function afterSave(Event $event, Entity $entity, ArrayObject $options)
    {
        if(!empty($entity->_afterSave)) {
            foreach($entity->_afterSave as $field => $data) {
                $entity->$field = $data;
            }
            $fieldConfig = array_intersect_key($this->config(),$entity->_afterSave);
            return $this->writeFiles($fieldConfig,$event,$entity,$options);
        }
    }

    /**
     * Deletes the files after the entity is deleted
     *
     * @param \Cake\Event\Event $event The afterDelete event that was fired
     * @param \Cake\ORM\Entity $entity The entity that was deleted
     * @param \ArrayObject $options the options passed to the delete method
     * @return void|false
     */
    public function afterDelete(Event $event, Entity $entity, ArrayObject $options)
    {
        foreach ($this->config() as $field => $settings) {
            if (Hash::get($settings, 'keepFilesOnDelete', true)) {
                continue;
            }

            $dirField = Hash::get($settings, 'fields.dir', 'dir');

            $file = [$entity->{$dirField} . $entity->{$field}];
            $writer = $this->getWriter($entity, [], $field, $settings);
            $success = $writer->delete($file);

            if ((new Collection($success))->contains(false)) {
                return false;
            }
        }
    }

    /**
     * Retrieves an instance of a path processor which knows how to build paths
     * for a given file upload
     *
     * @param \Cake\ORM\Entity $entity an entity
     * @param array $data the data being submitted for a save
     * @param string $field the field for which data will be saved
     * @param array $settings the settings for the current field
     * @return \Josegonzalez\Upload\File\Path\AbstractProcessor
     */
    public function getPathProcessor(Entity $entity, $data, $field, $settings)
    {
        $default = 'Josegonzalez\Upload\File\Path\DefaultProcessor';
        $processorClass = Hash::get($settings, 'pathProcessor', $default);
        if (is_subclass_of($processorClass, 'Josegonzalez\Upload\File\Path\ProcessorInterface')) {
            return new $processorClass($this->_table, $entity, $data, $field, $settings);
        }

        throw new UnexpectedValueException(sprintf(
            "'pathProcessor' not set to instance of ProcessorInterface: %s",
            $processorClass
        ));
    }

    /**
     * Retrieves an instance of a file writer which knows how to write files to disk
     *
     * @param \Cake\ORM\Entity $entity an entity
     * @param array $data the data being submitted for a save
     * @param string $field the field for which data will be saved
     * @param array $settings the settings for the current field
     * @return \Josegonzalez\Upload\File\Path\AbstractProcessor
     */
    public function getWriter(Entity $entity, $data, $field, $settings)
    {
        $default = 'Josegonzalez\Upload\File\Writer\DefaultWriter';
        $writerClass = Hash::get($settings, 'writer', $default);
        if (is_subclass_of($writerClass, 'Josegonzalez\Upload\File\Writer\WriterInterface')) {
            return new $writerClass($this->_table, $entity, $data, $field, $settings);
        }

        throw new UnexpectedValueException(sprintf(
            "'writer' not set to instance of WriterInterface: %s",
            $writerClass
        ));
    }

    /**
     * Creates a set of files from the initial data and returns them as key/value
     * pairs, where the path on disk maps to name which each file should have.
     * This is done through an intermediate transformer, which should return
     * said array. Example:
     *
     *   [
     *     '/tmp/path/to/file/on/disk' => 'file.pdf',
     *     '/tmp/path/to/file/on/disk-2' => 'file-preview.png',
     *   ]
     *
     * A user can specify a callable in the `transformer` setting, which can be
     * used to construct this key/value array. This processor can be used to
     * create the source files.
     *
     * @param \Cake\ORM\Entity $entity an entity
     * @param array $data the data being submitted for a save
     * @param string $field the field for which data will be saved
     * @param array $settings the settings for the current field
     * @param string $basepath a basepath where the files are written to
     * @return array key/value pairs of temp files mapping to their names
     */
    public function constructFiles(Entity $entity, $data, $field, $settings, $basepath)
    {
        $default = 'Josegonzalez\Upload\File\Transformer\DefaultTransformer';
        $transformerClass = Hash::get($settings, 'transformer', $default);
        $results = [];
        if (is_subclass_of($transformerClass, 'Josegonzalez\Upload\File\Transformer\TransformerInterface')) {
            $transformer = new $transformerClass($this->_table, $entity, $data, $field, $settings);
            $results = $transformer->transform();
            foreach ($results as $key => $value) {
                $results[$key] = $basepath . '/' . $value;
            }
        } elseif (is_callable($transformerClass)) {
            $results = $transformerClass($this->_table, $entity, $data, $field, $settings);
            foreach ($results as $key => $value) {
                $results[$key] = $basepath . '/' . $value;
            }
        } else {
            throw new UnexpectedValueException(sprintf(
                "'transformer' not set to instance of TransformerInterface: %s",
                $transformerClass
            ));
        }
        return $results;
    }
    
    
     /**
     * Defers fields and related request data to afterSave method if 
     * the field settings use {primaryKey} in the path.
     * @param  \Cake\ORM\Entity $entity
     * @returns array - Fields and settings with deferred fields filtered out.
     */
    protected function deferFields($entity)
    {
        $fields = $this->config();
        $entity->_afterSave = [];
        foreach ($fields as $field => $settings) {
            if(strpos(Hash::get($settings, 'path',''),'{primaryKey}') !== false) {
                $entity->_afterSave[$field] = $entity->get($field);
                $entity->set($field,'');
                unset($fields[$field]);
            }
        }
        return $fields;
    }
    
    /**
     * Prepares and writes the entity file data for the provided fields.
     * @param type $fields
     * @param Event $event
     * @param Entity $entity
     * @param ArrayObject $options
     * @return boolean
     * @throws RuntimeException
     */
    protected function writeFiles($fields,Event $event, Entity $entity, ArrayObject $options)
    {
        foreach ($fields as $field => $settings) {
            // Skip configuration setting
            if (Hash::get((array)$entity->get($field), 'error') !== UPLOAD_ERR_OK) {
                continue;
            }

            $data = $entity->get($field);
            $path = $this->getPathProcessor($entity, $data, $field, $settings);
            $basepath = $path->basepath();
            $filename = $path->filename();
            $data['name'] = $filename;
            $files = $this->constructFiles($entity, $data, $field, $settings, $basepath);

            $writer = $this->getWriter($entity, $data, $field, $settings);
            $success = $writer->write($files);

            if ((new Collection($success))->contains(false)) {
                return false;
            }

            $entity->set($field, $filename);
            $entity->set(Hash::get($settings, 'fields.dir', 'dir'), $basepath);
            $entity->set(Hash::get($settings, 'fields.size', 'size'), $data['size']);
            $entity->set(Hash::get($settings, 'fields.type', 'type'), $data['type']);
        }
        
        if($event->name() === 'Model.afterSave' && $entity->dirty()) {
            $result = $this->saveNoCallbacks($entity);
            if(!$result) {
                throw new RuntimeException(__('Some file data was not saved'));
            }
        }
    }
    
    protected function saveNoCallbacks($entity)
    {
        $primaryKey = $this->_table->primaryKey();
        $dirtyFields = $entity->extract($this->_table->schema()->columns(), true);
        $result = (boolean) (!empty($entity->$primaryKey) 
            ? $this->_table->updateAll($dirtyFields, [$primaryKey => $entity->$primaryKey]) : false);
        $entity->clean();

        return $result ? $entity : false;
    }
}
