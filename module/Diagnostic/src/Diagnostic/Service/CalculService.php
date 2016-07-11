<?php
namespace Diagnostic\Service;

use Zend\ServiceManager\ServiceLocatorAwareInterface;
use Zend\ServiceManager\ServiceLocatorAwareTrait;
use Zend\Session\Container;

/**
 * CalculService
 *
 * @package Diagnostic\Service
 * @author Jerome De Almeida <jerome.dealmeida@vesperiagroup.com>
 */
class CalculService implements ServiceLocatorAwareInterface
{
    use ServiceLocatorAwareTrait;

    public function calcul() {

        //retrieve questions
        $questionService = $this->getServiceLocator()->get('Diagnostic\Service\QuestionService');
        $questions = $questionService->getQuestions();

        //retrieve results and questions
        $container = new Container('diagnostic');
        $results = ($container->offsetExists('result')) ? $container->result : [];

        $totalPoints = 0;
        $totalPointsTarget = 0;
        $totalThreshold = 0;
        $globalPoints = [];
        $globalThreshold = [];
        $recommandations = [];
        foreach ($questions as $questionId =>$question) {

            $categoryId = $question->getCategoryId();
            $threshold = $question->getThreshold();

            if (array_key_exists($questionId, $results)) {
                if (strlen($results[$questionId]['notes'])) {
                    $points = $results[$questionId]['maturity'] * $threshold;
                    $pointsTarget = $results[$questionId]['maturityTarget'] * $threshold;
                    $recommandations[$question->getId()] = [
                        'recommandation' => $results[$questionId]['recommandation'],
                        'threshold' => $threshold,
                        'domaine' => $question->getCategoryTranslationKey(),
                        'gravity' => '/img/gravity_' . $results[$questionId]['gravity'] . '.png',
                        'maturity' => $this->getImgMaturity($results[$questionId]['maturity']),
                        'maturityTarget' => $this->getImgMaturity($results[$questionId]['maturityTarget']),
                    ];

                    $totalPoints += $points;
                    $totalPointsTarget += $pointsTarget;
                    $globalPoints[$categoryId] = array_key_exists($categoryId, $globalPoints) ? $globalPoints[$categoryId] + $points : $points;
                    
                    $totalThreshold += $threshold;
                    $globalThreshold[$categoryId] = array_key_exists($categoryId, $globalThreshold) ? $globalThreshold[$categoryId] + $threshold : $threshold;
                }
            }
        }

        $total = ($totalThreshold) ? round($totalPoints / $totalThreshold * 100 / 3) : 0;
        $totalTarget = ($totalThreshold) ? round($totalPointsTarget / $totalThreshold * 100 / 3) : 0;

        $totalCategory = [];
        foreach($globalPoints as $categoryId => $points) {
            $totalCategory[$categoryId] = round($points / $globalThreshold[$categoryId] * 100 / 3);
        }

        //order recommandation by threshold
        $tmpArray = [];
        foreach($recommandations as $questionId => $recommandation) {
            $tmpArray[$questionId] = $recommandation['threshold'];
        }
        asort($tmpArray);
        $recommandationsSort = [];
        foreach($tmpArray as $questionId => $value) {
            $recommandationsSort[$questionId] = $recommandations[$questionId];
        }

        return [
            'total' => $total,
            'totalTarget' => $totalTarget,
            'totalCategory' => $totalCategory,
            'recommandations' => $recommandationsSort,
        ];
    }

    /**
     * Get Img Maturity
     *
     * @param $maturity
     * @return string
     */
    public function getImgMaturity($maturity) {

        switch ($maturity) {
            case 3:
                $img = '/img/mat_ok.png';
                break;
            case 2:
                $img = '/img/mat_moyen.png';
                break;
            case 1:
                $img = '/img/mat_plan.png';
                break;
            case 0:
                $img = '/img/mat_none.png';
                break;
        }

        return $img;
    }
}