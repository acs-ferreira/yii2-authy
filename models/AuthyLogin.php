<?php

namespace geoffry304\authy\models;

use Yii;
use \yii\db\ActiveRecord;

/**
 * This is the model class for table "authy_login".
 *
 * @property int $id
 * @property int $authyid
 * @property string $ip
 * @property string $expire_at
 * @property string $hostname
 * @property string $device_type
 * @property string $ip_org
 * @property string $ip_country
 * @property string $os
 * @property string $browser
 * @property string $brand
 *
 * @property Authy $authy
 */
class AuthyLogin extends ActiveRecord {

public $remember_computer;
    /**
     * @inheritdoc
     */
    public static function tableName() {
        return 'authy_login';
    }

    /**
     * @inheritdoc
     */
    public function rules() {
        return [
            [['authyid', 'ip'], 'required'],
            [['authyid'], 'integer'],
            [['expire_at'], 'safe'],
            [['ip', 'device_type', 'ip_org', 'ip_country', 'os', 'browser', 'brand'], 'string', 'max' => 255],
            [['hostname'], 'string', 'max' => 300],
            [['authyid'], 'exist', 'skipOnError' => true, 'targetClass' => Authy::className(), 'targetAttribute' => ['authyid' => 'id']],
        ];
    }

    /**
     * @inheritdoc
     */
    public function attributeLabels() {
        return [
            'id' => Yii::t('authy', 'ID'),
            'authyid' => Yii::t('authy', 'Authyid'),
            'ip' => Yii::t('authy', 'Ip'),
            'expire_at' => Yii::t('authy', 'Expire At'),
            'hostname' => Yii::t('authy', 'Hostname'),
            'device_type' => Yii::t('authy', 'Device Type'),
            'ip_org' => Yii::t('authy', 'Ip Org'),
            'ip_country' => Yii::t('authy', 'Ip Country'),
            'os' => Yii::t('authy', 'Os'),
            'browser' => Yii::t('authy', 'Browser'),
            'brand' => Yii::t('authy', 'Brand'),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getAuthy() {
        return $this->hasOne(Authy::className(), ['id' => 'authyid']);
    }

    public function detectAttributes() {
        $this->ip = Detect::ip();
        $this->hostname = Detect::ipHostname();
        $this->device_type = Detect::deviceType();
        $this->ip_org = Detect::ipOrg();
        $this->ip_country = Detect::ipCountry();
        $this->os = Detect::os();
        $this->browser = Detect::browser();
        $this->brand = Detect::brand();
    }

    public function getHashedString() {
        return $this->authy->authyid .";". $this->id .";". $this->os .";". $this->browser .";". $this->device_type;
    }

    public function getBrowserConcat(){
        $string = "";
        if ($this->brand != "Unknown Brand"){
            $string .= $this->brand . " ";
        } else {
            $string .= $this->browser . " ";
        }
        $string .= "(" . $this->os . ")";
        return $string;
    }

    public function checkIfCurrent(){
        $model = self::currentValidate();
        return ($model) ? (($this->id == $model->id) ? "<i class=\"fa fa-check\" aria-hidden=\"true\"></i>" : false) : false;
    }
    
    public static function currentValidate(){
        $authy = Authy::find()->where(['userid' => Yii::$app->user->id])->one();
        if ($authy){
            $cookie = self::checkCookie();
            if ($cookie != null){
               $cookie_array = explode(";", self::checkCookie());
               if ($cookie_array[0] == $authy->authyid) {
                    return AuthyLogin::findByCookie($authy->id, $cookie_array);
               }
            }
        }
        return false;
    }


    public function createCookie($default_expiry) {
        $cookies = Yii::$app->response->cookies;
        $cookies->add(new \yii\web\Cookie([
            'name' => 'sft',
            'value' => $this->getHashedString(),
            'expire' =>($this->remember_computer) ? (time() + $default_expiry) : 0
        ]));
    }
    
    public static function checkCookie() {
        $cookies = Yii::$app->request->cookies;
        if (($cookie = $cookies->get('sft')) !== null) {
            $value = $cookie->value;
            return $value;
        }
        return null;
    }

    public function getDeleteUrl(){
        $text = "<i class=\"fa fa-times\" aria-hidden=\"true\"></i>";
        $url = \yii\helpers\Url::to(["/authy/default/delete",'id'=>$this->id]);
        $options = ['data-toggle' => "tooltip", 'title' => Yii::t('authy', 'Remove authenticated device'),'data-confirm' => Yii::t('authy','Are you sure you want to delete this item?')];
        return \yii\helpers\Html::a($text, $url, $options);

    }

    public static function findByCookie($authyid, $cookie){

        $authylogin = AuthyLogin::findOne($cookie[1]);
        if ($authylogin){
            return ((Detect::os() == $cookie[2] && Detect::os() == $authylogin->os) &&
                    (Detect::browser() == $cookie[3] && Detect::browser() == $authylogin->browser) &&
                    (Detect::deviceType() == $cookie[4] && Detect::deviceType() == $authylogin->device_type)) ? $authylogin : false;
        } else {
            return false;
        }


    }
    
    public static function addNewRecord($authy, $module,$remember){
        $model = new self;
        $model->authyid = $authy->id;
            $model->remember_computer = $remember;
            $model->expire_at = date('Y-m-d  H:i:s', time() + $module->default_expirytime);
            $model->detectAttributes();
            if ($model->save()){
                $model->createCookie($module->default_expirytime);
                if ($module->send_mail){
                    $model->sendMail($module);
                }
            }
    }
    
    public function sendMail($module){
        /** @var Mailer $mailer */
        
        $mailer = Yii::$app->mailer;
        $model = $this;
        $subject = Yii::t('authy', 'Successful Login From New IP');
        $useremail = \app\models\User::findOne($model->authy->userid)->email;
        $result = $mailer->compose($module->emailViewPath . '/confirmEmail', compact("subject", "model", "useremail"))
            ->setFrom($module->send_mail_from)
            ->setTo($useremail)
            ->setSubject($subject)
            ->send();
        
        return $result;
    }
}
