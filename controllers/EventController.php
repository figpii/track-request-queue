<?php

namespace app\controllers;

use app\models\TrackJob;
use Yii;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\Response;
use yii\filters\VerbFilter;

class EventController extends Controller
{

    /**
     * {@inheritdoc}
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
        ];
    }

    /**
     * Displays homepage.
     *
     * @return string
     */
    public function actionIndex(): array
    {
        \Yii::$app->response->format = Response::FORMAT_JSON;

        $request = Yii::$app->request;
        $queryParams = $request->getQueryParams();

        Yii::$app->queue->push(new TrackJob([
            'queryParams' => $queryParams
        ]));

        return [
            'status' => 'success'
        ];
    }
}
