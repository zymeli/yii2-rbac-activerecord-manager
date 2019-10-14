<?php

namespace zymeli\yii2RbacActiveRecordManager\models;

use Yii;

/**
 * This is the model class for table "{{%auth_item_child}}".
 *
 * @property integer $parent_item_id dbField: int(9) unsigned
 * @property integer $child_item_id dbField: int(9) unsigned
 *
 * @property AuthItem $parentAuthItem
 * @property AuthItem $childAuthItem
 */
class AuthItemChild extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%auth_item_child}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['parent_item_id', 'child_item_id'], 'required'],
            [['parent_item_id', 'child_item_id'], 'integer', 'min' => 0, 'max' => 999999999],
            [['parent_item_id', 'child_item_id'], 'unique', 'targetAttribute' => ['parent_item_id', 'child_item_id']],
            [['parent_item_id'], 'exist', 'skipOnError' => true, 'targetClass' => AuthItem::class, 'targetAttribute' => ['parent_item_id' => 'item_id']],
            [['child_item_id'], 'exist', 'skipOnError' => true, 'targetClass' => AuthItem::class, 'targetAttribute' => ['child_item_id' => 'item_id']],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'parent_item_id' => 'Parent Item ID',
            'child_item_id' => 'Child Item ID',
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getParentAuthItem()
    {
        return $this->hasOne(AuthItem::class, ['item_id' => 'parent_item_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getChildAuthItem()
    {
        return $this->hasOne(AuthItem::class, ['item_id' => 'child_item_id']);
    }
}
