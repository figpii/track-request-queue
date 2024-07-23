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

        if (
            (empty($queryParams['idsite']) || empty($queryParams['analytics_id'])) ||
            (empty($queryParams['event_name']) || empty($queryParams['e_c'])) ||
            (empty($queryParams['test_id']) || empty($queryParams['dimension1'])) ||
            (empty($queryParams['variation_id']) || empty($queryParams['dimension2'])) ||
            (empty($queryParams['user_id']) || empty($queryParams['vid']) || empty($queryParams['uid']))
        ) {
            return [
                'status' => 'missing required parameter, please check https://kb.figpii.com/',
            ];
        }

        Yii::$app->queue->push(new TrackJob([
            'queryParams' => $queryParams
        ]));

        return [
            'status' => 'success'
        ];
    }
}
