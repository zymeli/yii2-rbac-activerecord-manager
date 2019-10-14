<?php

namespace zymeli\yii2RbacActiveRecordManager\models;

use Yii;

/**
 * This is the model class for table "{{%auth_rule}}".
 *
 * @property string $name dbField: varchar(60)
 * @property resource $data dbField: blob null
 * @property integer $created_at dbField: int(11) null
 * @property integer $updated_at dbField: int(11) null
 *
 * @property-read mixed $dataObject get only data populated
 *
 * @property AuthItem[] $authItems
 */
class AuthRule extends \yii\db\ActiveRecord
{
    /** @var bool the data.object is populated */
    private $_is_populated = false;
    private $_dataObject;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%auth_rule}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['name'], 'required'],
            [['data'], 'string'],
            [['created_at', 'updated_at'], 'integer', 'min' => 0],
            [['created_at', 'updated_at'], 'default', 'value' => time()],
            [['name'], 'string', 'max' => 60],
            [['name'], 'unique'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'name' => 'Name',
            'data' => 'Data',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * populate data to object
     */
    public function populateObject()
    {
        if (!$this->_is_populated) {
            // data
            if ($this->data !== null and $this->data !== '') {
                $data = $this->data;
                $data = @unserialize(is_resource($data) ? stream_get_contents($data) : $data);
                $this->_dataObject = ($data === false ? null : $data);
            }
        }
        $this->_is_populated = true;
    }

    /**
     * @return mixed
     */
    public function getDataObject()
    {
        $this->_is_populated or $this->populateObject();
        return $this->_dataObject;
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthItems()
    {
        return $this->hasMany(AuthItem::class, ['rule_name' => 'name']);
    }
}
