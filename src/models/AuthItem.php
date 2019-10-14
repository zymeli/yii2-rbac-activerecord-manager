<?php

namespace zymeli\yii2RbacActiveRecordManager\models;

use Yii;
use yii\rbac\Item;
use yii\rbac\Permission;
use yii\rbac\Role;

/**
 * This is the model class for table "{{%auth_item}}".
 *
 * @property integer $item_id dbField: int(9) unsigned
 * @property int|bool $is_scope dbField: tinyint(1) unsigned
 * @property string $name dbField: varchar(64)
 * @property integer $type dbField: smallint(6)
 * @property string $description dbField: text null
 * @property string $rule_name dbField: varchar(60) null
 * @property string $data dbField: text null
 * @property integer $created_at dbField: int(11) null
 * @property integer $updated_at dbField: int(11) null
 *
 * @property-read mixed $dataObject get only data populated
 * @property-read Item $itemObject get populated this to Item
 *
 * @property AuthAssignment[] $authAssignments
 * @property AuthRule $authRule
 * @property AuthItemChild[] $authParents
 * @property AuthItemChild[] $authChilds
 * @property AuthItem[] $childAuthItems
 * @property AuthItem[] $parentAuthItems
 * @property AuthScope[] $authScopes
 */
class AuthItem extends \yii\db\ActiveRecord
{
    const TYPE_ROLE = 1;
    const TYPE_PERMISSION = 2;

    /** @var bool the data.object and type.object is populated */
    private $_is_populated = false;
    private $_dataObject, $_itemObject;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%auth_item}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['name', 'type'], 'required'],
            [['is_scope'], 'default', 'value' => 0],
            [['is_scope'], 'boolean'],
            [['type'], 'in', 'range' => array_keys(static::getTypeOptions())],
            [['description', 'data'], 'string'],
            [['name', 'rule_name'], 'string', 'max' => 60],
            [['created_at', 'updated_at'], 'integer', 'min' => 0],
            [['created_at', 'updated_at'], 'default', 'value' => time()],
            [['rule_name'], 'exist', 'skipOnError' => true, 'targetClass' => AuthRule::class, 'targetAttribute' => ['rule_name' => 'name']],
            [['is_scope', 'name'], 'validateUniqueNameWhenNoScope'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'item_id' => 'Item ID',
            'is_scope' => 'Is Scope',
            'name' => 'Name',
            'type' => 'Type',
            'description' => 'Description',
            'rule_name' => 'Rule Name',
            'data' => 'Data',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
        ];
    }

    /**
     * inline validator: item.name must be unique when no scope.
     * @param $attribute
     * @param $params
     * @param $validator
     */
    public function validateUniqueNameWhenNoScope($attribute, $params, $validator)
    {
        if ($this->type == static::TYPE_ROLE) $this->is_scope = true;
        if (!$this->is_scope) {
            $isExists = static::find()->where(['!=', 'item_id', $this->item_id])->andWhere(['name' => $this->name])->exists();
            if ($isExists) {
                $this->addError($attribute, 'The item.name must be unique when no scope.');
            }
        }
    }

    /**
     * @return array
     */
    public static function getTypeOptions()
    {
        return [
            self::TYPE_ROLE => 'ROLE',
            self::TYPE_PERMISSION => 'PERMISSION',
        ];
    }

    /**
     * @return bool
     */
    public function isRole()
    {
        return $this->type == self::TYPE_ROLE;
    }

    /**
     * @return bool
     */
    public function isPermission()
    {
        return $this->type == self::TYPE_PERMISSION;
    }

    /**
     * populate data and this
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
            // item
            $class = ($this->isRole() ? Role::class : Permission::class);
            $this->_itemObject = new $class([
                'name' => $this->name,
                'type' => $this->type,
                'description' => $this->description,
                'ruleName' => ($this->rule_name ?: null),
                'data' => $this->_dataObject,
                'createdAt' => $this->created_at,
                'updatedAt' => $this->updated_at,
            ]);
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
     * @return Item|Permission|Role
     */
    public function getItemObject()
    {
        $this->_is_populated or $this->populateObject();
        return $this->_itemObject;
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthAssignments()
    {
        return $this->hasMany(AuthAssignment::class, ['item_id' => 'item_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthRule()
    {
        return $this->hasOne(AuthRule::class, ['name' => 'rule_name']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthParents()
    {
        return $this->hasMany(AuthItemChild::class, ['parent_item_id' => 'item_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthChilds()
    {
        return $this->hasMany(AuthItemChild::class, ['child_item_id' => 'item_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws \Exception
     */
    public function getChildAuthItems()
    {
        return $this->hasMany(AuthItem::class, ['item_id' => 'child_item_id'])->via('authParents');
    }

    /**
     * @return \yii\db\ActiveQuery
     * @throws \Exception
     */
    public function getParentAuthItems()
    {
        return $this->hasMany(AuthItem::class, ['item_id' => 'parent_item_id'])->via('authChilds');
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthScopes()
    {
        return $this->hasMany(AuthScope::class, ['item_id' => 'item_id']);
    }
}
