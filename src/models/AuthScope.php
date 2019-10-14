<?php

namespace zymeli\yii2RbacActiveRecordManager\models;

use Yii;

/**
 * This is the model class for table "{{%auth_scope}}".
 *
 * @property integer $item_id dbField: int(9) unsigned
 * @property string $scope_id dbField: varchar(60)
 * @property integer $created_at dbField: int(11) null
 *
 * @property AuthItem $authItem
 */
class AuthScope extends \yii\db\ActiveRecord
{
    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%auth_scope}}';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['item_id', 'scope_id'], 'required'],
            [['item_id'], 'integer', 'min' => 0, 'max' => 999999999],
            [['scope_id'], 'string', 'max' => 60],
            [['created_at'], 'integer', 'min' => 0],
            [['created_at'], 'default', 'value' => time()],
            [['item_id', 'scope_id'], 'unique', 'targetAttribute' => ['item_id', 'scope_id']],
            [['item_id'], 'exist', 'skipOnError' => true, 'targetClass' => AuthItem::class, 'targetAttribute' => ['item_id' => 'item_id']],
            [['item_id', 'scope_id'], 'validateUniqueWithItemName'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels()
    {
        return [
            'item_id' => 'Item ID',
            'scope_id' => 'Scope ID',
            'created_at' => 'Created At',
        ];
    }

    /**
     * inline validator: item.name must be unique with this scope.
     * @param $attribute
     * @param $params
     * @param $validator
     */
    public function validateUniqueWithItemName($attribute, $params, $validator)
    {
        $name = AuthItem::find()->where(['item_id' => $this->item_id])->select('name')->scalar();
        $isExists = static::find()->where(['scope_id' => $this->scope_id])
            ->joinWith('authItem')->andWhere(['name' => $name])
            ->exists();
        if ($isExists) {
            $this->addError($attribute, 'The item.name must be unique with this scope.');
        }
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthItem()
    {
        return $this->hasOne(AuthItem::class, ['item_id' => 'item_id']);
    }
}
