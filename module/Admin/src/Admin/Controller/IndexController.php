<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Admin\Controller;

use Admin\InputFilter\EmailNotExistFilter;
use Admin\InputFilter\UserCreateFormFilter;
use Admin\InputFilter\UserFormFilter;
use Diagnostic\Controller\AbstractController;
use Zend\Session\Container;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractController
{
    protected $translator;
    protected $userService;
    protected $userTokenService;
    protected $questionService;
    protected $categoryService;
    protected $languageService;
    protected $userForm;
    protected $adminQuestionForm;
    protected $adminCategoryForm;
    protected $adminLanguageForm;
    protected $adminTemplateForm;
    protected $adminSettingForm;
    protected $adminAddTranslationForm;

    /**
     * Index
     *
     * @return \Zend\Http\Response|ViewModel
     */
    public function usersAction()
    {
        $id = $this->getEvent()->getRouteMatch()->getParam('id');
        //retrieve users
        $userService = $this->get('userService');
        $users = $userService->getUsers();

        //retrieve current user
        $container = new Container('user');
        $currentEmail = $container->email;

        $arrayView = [
            'users' => $users,
            'id' => $id,
        ];

        if (!is_null($id)) {
            $form = $this->get('userForm');

            $userFormFilter = new UserFormFilter($this->get('dbAdapter'));
            $form->setInputFilter($userFormFilter);

            $arrayView['form'] = $form;

            $currentUser = $userService->getUserById($id);

            $userToModify = null;
            foreach ($currentUser as $user) {
                $userToModify = $user;
                if ($user->getId() == $id) {
                    $form->bind($user);
                    if ($user->getEmail() == $currentEmail) {
                        $arrayView['current'] = true;
                    }
                }
            }

            //form is post and valid
            $request = $this->getRequest();
            if ($request->isPost()) {

                if ($request->getPost('email') != $userToModify->email) {
                    $emailNotExistFilter = new EmailNotExistFilter($this->get('dbAdapter'));
                    $form->setInputFilter($emailNotExistFilter);
                }

                $form->setData($request->getPost());

                $emailUserToModify = $userToModify->email;
                if ($form->isValid()) {
                    $formData = $form->getData();
                    if (is_null($formData->admin)) {
                        unset($formData->admin);
                    }
                    if ($request->getPost('email') != $emailUserToModify) {
                        $userTokenService = $this->get('userTokenService');
                        $userTokenService->deleteByEmail($emailUserToModify);
                    }
                    $userService->update($id, (array)$formData);

                    //redirect
                    return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'users']);
                }
            }

        }

        //send to view
        return new ViewModel($arrayView);
    }

    /**
     * Add a user
     *
     * @return \Zend\Http\Response|ViewModel
     */
    public function addUserAction()
    {
        $form = $this->get('userForm');

        $emailNotExistFilter = new EmailNotExistFilter($this->get('dbAdapter'));
        $form->setInputFilter($emailNotExistFilter);

        $form->get('submit')->setValue('__add');

        //form is post and valid
        $request = $this->getRequest();
        if ($request->isPost()) {
            $form->setData($request->getPost());
            if ($form->isValid()) {
                $formData = $form->getData();

                $userService = $this->get('userService');
                $userService->create((array)$formData);

                //redirect
                return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'users']);
            }
        }

        //send to view
        return new ViewModel([
            'form' => $form
        ]);
    }

    /**
     * Questions
     *
     * @return ViewModel
     */
    public function questionsAction()
    {
        $location_lang = '/var/www/diagnostic/language/';

        $error_upload = 0; // File not valid
        $error_id = 0; // ID exist

        //retrieve questions
        $questionService = $this->get('questionService');
        $questions = $questionService->getBddQuestions();
        $questions_max = count($questions);
        $max_quest = 1;
        $i = 1;
        $j = 1;
        // Put the highest question id in $max_quest
        while ($i <= $questions_max) {
            if (isset($questions[$j])) {
                if ($questions[$j]->id > $max_quest) {$max_quest = $questions[$j]->id;}
                $i++;
                $j++;
            }else {$j++;}
        }

        //retrieve categories
        $categoryService = $this->get('categoryService');
        $categories = $categoryService->getBddCategories();
        $categories_max = count($categories);
        $max_categ = 1;
        $i = 1;
        $j = 1;
        // Put the highest category id in $max_categ
        while ($i <= $categories_max) {
            if (isset($categories[$j])) {
                if ($categories[$j]->id > $max_categ) {$max_categ = $categories[$j]->id;}
                $i++;
                $j++;
            }else {$j++;}
        }

        $request = $this->getRequest();
        $form = $this->get('adminQuestionForm');

        $form->get('submit')->setValue('__export');

        //form is post and valid
        if ($request->isPost()) {

            // Upload
            if (isset($_POST['submit_file'])) {

                if (!empty($_FILES['file']['tmp_name'])) {

                    $content = file_get_contents($_FILES['file']['tmp_name']);
                    $tab = json_decode($content, true);

                    // Verify if the file is correct
                    $error_file = 0;
                    $i = 1;
                    while (isset($tab[$i-1])) {
                        if (count($tab[$i-1]) < 2 || !isset($tab[$i-1]['id']) || !isset($tab[$i-1]['questions'])) {$error_file = 1;}
                        $j = 1;
                        while (isset($tab[$i-1]['questions'][$j-1])) {
                            if (count($tab[$i-1]['questions'][$j-1]) < 4 || !isset($tab[$i-1]['questions'][$j-1]['id']) || !isset($tab[$i-1]['questions'][$j-1]['threat']) || !isset($tab[$i-1]['questions'][$j-1]['weight']) || !isset($tab[$i-1]['questions'][$j-1]['blocking'])) {$error_file = 1;}
                            $j++;
                        }
                        $i++;
                    }

                    if ($error_file == 1) {$tab = '';}
                    else {
                        $i = 1;
                        while (isset($tab[$i-1])) {
                            // Avoid to put a wrong category id
                            $j = $i + 1;
                            while (isset($tab[$j-1])) {
                                if ($tab[$i-1]['id'] == $tab[$j-1]['id']) {$error_id = 1;}
                                $j++;
                            }

                            // Avoid to put a wrong question id
                            $k = 1;
                            while (isset($tab[$i-1]['questions'][$k-1])) {
                                $m = $i;
                                while (isset($tab[$m-1])) {
                                    if ($m == $i) {$l = $k + 1;}
                                    else {$l = 1;}
                                    while (isset($tab[$m-1]['questions'][$l-1])) {
                                        if ($tab[$i-1]['questions'][$k-1]['id'] == $tab[$m-1]['questions'][$l-1]['id']) {$error_id = 1;}
                                        $l++;
                                    }
                                    $m++;
                                }
                                $k++;
                            }
                            $i++;
                        }
                    }

                    if ($tab != '' && $error_id == 0) {


                        ///////////////////////// CATEGORY PART \\\\\\\\\\\\\\\\\\\\\\\\\\


                        // Delete the previous category database
                        $i = 1;
                        while ($max_categ >= $i) {
                            if (isset($categories[$i])) {
                                $categoryService->delete($i);
                            }
                            $i++;
                        }

                        // Set category Uid
                        $hash_categ = [];
                        $i = 1;
                        while (isset($tab[$i-1])) {
                            $hash_categ[$i]['translation_en'] = $tab[$i-1]['translation_en'];
                            $i++;
                        }

                        // Import the new category database with the things needed for categories database
                        $tab_database = [];
                        $i = 1;
                        $j = 1;
                        while (isset($tab[$i-1])) {
                            if ($tab[$i-1]['id'] != $j) {$j = $tab[$i-1]['id'];}
                            $tab_database[$i-1]['id'] = $j;
                            $tab_database[$i-1]['translation_key'] = '__category' . $j;
                            $tab_database[$i-1]['uid'] = md5(serialize($hash_categ[$i]));
                            $categoryService->create($tab_database[$i-1]);
                            $i++;
                        }
                        $categoryService->resetCache();

                        $categories = $categoryService->getBddCategories();

                        // Write in category file
                        $file_lang = fopen($location_lang . 'languages.txt', 'r');
                        for ($l=1; $l<$_SESSION['nb_lang']; $l++) {
                            $temp_lang = substr(fgets($file_lang, 4096), 0, -1);

                            rename($location_lang . $temp_lang . '/categories.po', $location_lang . $temp_lang . '/categories_temp.po');
                            $file_temp = fopen($location_lang . $temp_lang . '/categories_temp.po', 'r');
                            $file = fopen($location_lang . $temp_lang . '/categories.po', 'w');
                            while (!feof($file_temp)) {
                                $temp = fgets($file_temp, 4096);
                                if ($temp == PHP_EOL) {break;}
                                fputs($file, $temp);
                            }

                            $i = 1;
                            $j = 1;
                            while (isset($tab[$i-1])) {
                                if ($tab[$i-1]['id'] != $j) {$j = $tab[$i-1]['id'];}
                                fputs($file, PHP_EOL);
                                fputs($file, 'msgid "__category' . $j . '"');
                                fputs($file, PHP_EOL);
                                // Write if the translation exist. If not, create it empty
                                if (isset($tab[$i-1]['translation_' . $temp_lang])) {
                                    fputs($file, 'msgstr "' . $tab[$i-1]['translation_' . $temp_lang] . '"');
                                }else {
                                    fputs($file, 'msgstr ""');
                                }
                                fputs($file, PHP_EOL);
                                $i++;
                            }
                            fclose($file_temp);
                            fclose($file);
                            unlink($location_lang . $temp_lang . '/categories_temp.po');

                            // compile from po to mo
                            shell_exec('msgfmt ' . $location_lang . $temp_lang . '/categories.po -o ' . $location_lang . $temp_lang . '/categories.mo');
                        }
                        fclose($file_lang);


                        ///////////////////////// QUESTION PART \\\\\\\\\\\\\\\\\\\\\\\\\\


                        $max_quest_to_upload = 1; // Find the question with the highest number in the json file to upload
                        $i = 1; // iteration for categories
                        while (isset($tab[$i-1])) {
                            $j = 1; // iteration for questions in each category
                            while (isset($tab[$i-1]['questions'][$j-1])) {
                                if ($tab[$i-1]['questions'][$j-1]['id'] > $max_quest_to_upload) {$max_quest_to_upload = $tab[$i-1]['questions'][$j-1]['id'];}
                                $j++;
                            }
                            $i++;
                        }

                        // Delete the previous question database
                        $i = 1;
                        while ($max_quest >= $i) {
                            if (isset($questions[$i])) {
                                $questionService->delete($i);
                            }
                            $i++;
                        }

                        // Set question Uid
                        $hash_quest = [];
                        $i = 1;
                        $k = 1;
                        while (isset($tab[$i-1])) {
                            $j = 1;
                            while (isset($tab[$i-1]['questions'][$j-1])) {
                                $hash_quest[$k]['translation_en'] = $tab[$i-1]['questions'][$j-1]['translation_en'];

                                $file = fopen($location_lang . 'en/categories.po', 'r');
                                while (!feof($file)) {
                                    $temp = fgets($file, 4096);
                                    if (substr($temp, 7, -2) == '__category' . $tab[$i-1]['id']) {
                                        $temp = fgets($file, 4096);
                                        $hash_quest[$k]['category_translation'] = substr($temp, 8, -2);
                                        break;
                                    }
                                }
                                fclose($file);
                                $k++;
                                $j++;
                            }
                            $i++;
                        }

                        // Import the new question database with the things needed for questions database
                        $tab_database = [];
                        $i = 1; // iteration for categories
                        $k = 1; // iteration for id of questions
                        $l = 1; // iteration for UID of questions
                        while (isset($tab[$i-1])) {
                            $j = 1; // iteration for questions in each category
                            while (isset($tab[$i-1]['questions'][$j-1])) {
                                if ($tab[$i-1]['questions'][$j-1]['id'] != $k) {$k = $tab[$i-1]['questions'][$j-1]['id'];}
                                $tab_database[$j-1]['id'] = $k;
                                $tab_database[$j-1]['category_id'] = $tab[$i-1]['id'];
                                $tab_database[$j-1]['translation_key'] = '__question' . $k;
                                $tab_database[$j-1]['threat'] = $tab[$i-1]['questions'][$j-1]['threat'];
                                $tab_database[$j-1]['weight'] = $tab[$i-1]['questions'][$j-1]['weight'];
                                $tab_database[$j-1]['blocking'] = $tab[$i-1]['questions'][$j-1]['blocking'];
                                $tab_database[$j-1]['uid'] = md5(serialize($hash_quest[$l]));
                                $questionService->create($tab_database[$j-1]);
                                $j++;
                                $l++;
                            }
                            $i++;
                        }
                        $questionService->resetCache();

                        // Write in question file
                        $file_lang = fopen($location_lang . 'languages.txt', 'r');
                        for ($l=1; $l<$_SESSION['nb_lang']; $l++) {
                            $temp_lang = substr(fgets($file_lang, 4096), 0, -1);

                            rename($location_lang . $temp_lang . '/questions.po', $location_lang . $temp_lang . '/questions_temp.po');
                            $file_temp = fopen($location_lang . $temp_lang . '/questions_temp.po', 'r');
                            $file = fopen($location_lang . $temp_lang . '/questions.po', 'w');
                            while (!feof($file_temp)) {
                                $temp = fgets($file_temp, 4096);
                                if ($temp == PHP_EOL) {break;}
                                fputs($file, $temp);
                            }

                            // Compare questions eachother to write in the file ordered questions in ascending order
                            $k = 1; // iteration for id of questions
                            while ($k <= $max_quest_to_upload) {
                                $i = 1; // iteration for categories
                                $ok = 0; // Variable to verify if the next question is the right question to be in ascending order
                                while (isset($tab[$i-1])) {
                                    $j = 1; // iteration for questions in each category
                                    while (isset($tab[$i-1]['questions'][$j-1])) {
                                        if ($tab[$i-1]['questions'][$j-1]['id'] == $k) {
                                            fputs($file, PHP_EOL);
                                            fputs($file, 'msgid "__question' . $k . '"');
                                            fputs($file, PHP_EOL);
                                            // Write if the translation exist. If not, create it empty
                                            if (isset($tab[$i-1]['questions'][$j-1]['translation_' . $temp_lang])) {
                                                fputs($file, 'msgstr "' . $tab[$i-1]['questions'][$j-1]['translation_' . $temp_lang] . '"');
                                            }else {
                                                fputs($file, 'msgstr ""');
                                            }
                                            fputs($file, PHP_EOL);
                                            fputs($file, PHP_EOL);
                                            fputs($file, 'msgid "__question' . $k . 'help"');
                                            fputs($file, PHP_EOL);
                                            // Write if the translation exist. If not, create it empty
                                            if (isset($tab[$i-1]['questions'][$j-1]['translation_' . $temp_lang])) {
                                                fputs($file, 'msgstr "' . $tab[$i-1]['questions'][$j-1]['translation_help_' . $temp_lang] . '"');
                                            }else {
                                                fputs($file, 'msgstr " "');
                                            }
                                            fputs($file, PHP_EOL);
                                            $k++;
                                        }
                                        $j++;
                                    }
                                    $i++;
                                }

                                // check if the next question has the right id
                                $i = 1;
                                while (isset($tab[$i-1])) {
                                    $j = 1;
                                    while (isset($tab[$i-1]['questions'][$j-1])) {
                                        if ($tab[$i-1]['questions'][$j-1]['id'] == $k) {$ok = 1;}
                                        $j++;
                                    }
                                    $i++;
                                }
                                if ($ok == 0) {$k++;}
                            }
                            fclose($file_temp);
                            fclose($file);
                            unlink($location_lang . $temp_lang . '/questions_temp.po');

                            // compile from po to mo
                            shell_exec('msgfmt ' . $location_lang . $temp_lang . '/questions.po -o ' . $location_lang . $temp_lang . '/questions.mo');
                        }
                        fclose($file_lang);

                        return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'questions']);
                    }elseif ($tab == '') {$error_upload = 1;}
                }else {$error_upload = 1;}
            }

            // Export
            if (isset($_POST['submit'])) {


                ///////////////////////// CATEGORY PART \\\\\\\\\\\\\\\\\\\\\\\\\\


                // Delete category things we don't need in the json file
                $i = 1;
                while ($max_categ >= $i) {
                    if (isset($categories[$i])) {
                        unset($categories[$i]->uid);
                        unset($categories[$i]->translation_key);
                        unset($categories[$i]->new);
                    }
                    $i++;
                }

                // Write translation categories in the json file
                $file_lang = fopen($location_lang . 'languages.txt', 'r');
                for ($j=1; $j<$_SESSION['nb_lang']; $j++) {
                    $temp_lang = substr(fgets($file_lang, 4096), 0, -1);

                    $file = fopen($location_lang . $temp_lang . '/categories.po', 'r');
                    // Go to categories
                    while (!feof($file)) {
                        $temp = fgets($file, 4096);
                        if ($temp == PHP_EOL) {$temp = fgets($file, 4096); break;}
                    }

                    // Put translations of categories
                    $i = 1;
                    while ($max_categ >= $i) {
                        if (isset($categories[$i])) {
                            $temp = fgets($file, 4096);
                            $categories[$i] = (array)$categories[$i];
                            $categories[$i]['translation_' . $temp_lang] = substr($temp, 8, -2);
                            $categories[$i] = (object)$categories[$i];
                            $temp = fgets($file, 4096);
                            $temp = fgets($file, 4096);
                        }
                        $i++;
                    }
                    fclose($file);
                }
                fclose($file_lang);


                ///////////////////////// QUESTION PART \\\\\\\\\\\\\\\\\\\\\\\\\\


                // Delete question things we don't need in the json file
                $i = 1;
                while ($max_quest >= $i) {
                    if (isset($questions[$i])) {
                        unset($questions[$i]->translation_key);
                        unset($questions[$i]->category_translation_key);
                        unset($questions[$i]->translation_key_help);
                        unset($questions[$i]->uid);
                        unset($questions[$i]->new);
                    }
                    $i++;
                }

                // Write translation questions in the json file
                $file_lang = fopen($location_lang . 'languages.txt', 'r');
                for ($j=1; $j<$_SESSION['nb_lang']; $j++) {
                    $temp_lang = substr(fgets($file_lang, 4096), 0, -1);

                    $file = fopen($location_lang . $temp_lang . '/questions.po', 'r');
                    // Go to the question translations
                    while (!feof($file)) {
                        $temp = fgets($file, 4096);
                        if ($temp == PHP_EOL) {$temp = fgets($file, 4096); break;}
                    }

                    $i = 1;
                    while ($max_quest >= $i) {
                        if (isset($questions[$i])) {
                            $temp = fgets($file, 4096);
                            $questions[$i] = (array)$questions[$i];
                            $questions[$i]['translation_' . $temp_lang] = substr($temp, 8, -2);
                            $temp = fgets($file, 4096);
                            $temp = fgets($file, 4096);
                            $temp = fgets($file, 4096);
                            $questions[$i]['translation_help_' . $temp_lang] = substr($temp, 8, -2);
                            $questions[$i] = (object)$questions[$i];
                            $temp = fgets($file, 4096);
                            $temp = fgets($file, 4096);
                        }
                        $i++;
                    }
                    fclose($file);
                }
                fclose($file_lang);

                // Put questions into categories
                foreach ($categories as $category) {
                    $j=1;
                    $question = [];
                    for ($i=1; $i<=$max_quest; $i++) {
                        if (isset($questions[$i])) {
                            if ($questions[$i]->category_id == $category->id) {
                                $question[$j] = $questions[$i];
                                $question[$j] = (array)$question[$j];
                                unset($question[$j]['category_id']); // Not needed in the json file
                                $j++;
                            }
                        }else {$j++;}
                    }
                    $category->questions = array_values($question);
                }

                // Encode in a file
                $filename = 'questions_' . date('YmdHis') . '.json';
                $fichier = fopen('/var/www/diagnostic/' . $filename, 'w+');
                fwrite($fichier, json_encode(array_values($categories), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                fclose($fichier);

                // Ddl the file and delete it in the VM
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename=' . $filename);
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize('/var/www/diagnostic/' . $filename));
                readfile('/var/www/diagnostic/' . $filename);
                unlink('/var/www/diagnostic/' . $filename);
            }
        }

        //send to view
        return new ViewModel([
            'questions' => $questions,
            'form' => $form,
            'error_upload' => $error_upload,
            'error_id' => $error_id
        ]);
    }

    /**
     * Categories
     *
     * @return ViewModel
     */
    public function categoriesAction()
    {
        //retrieve categories
        $categoryService = $this->get('categoryService');
        $categories = $categoryService->getBddCategories();

        //send to view
        return new ViewModel([
            'categories' => $categories
        ]);
    }

    /**
     * Settings
     *
     * @return ViewModel
     */
    public function settingsAction()
    {
        $location_lang = '/var/www/diagnostic/language/';
        $location_mod = '/var/www/diagnostic/module/';
        $location_stat = '/var/www/diagnostic/data/resources/statistics_';

        $error_res = 0; // Number for the statistic not valid
        $success_upload = 0; // Success for the 1st record
        $success_upload2 = 0; // Success for the 2nd record

        $request = $this->getRequest();
        $form = $this->get('adminSettingForm');

        //////////////////////// Bind select \\\\\\\\\\\\\\\\\\\\\\\\\\
        $file_config = fopen($location_mod . 'Diagnostic/config/module.config.php', 'r');
        while(!feof($file_config)) {
            $temp_config = fgets($file_config, 4096);
            if($temp_config == "    'translator' => [" . PHP_EOL){$temp_config = fgets($file_config, 4096); $value_select = substr($temp_config, 21, -3); break;}
        }
        fclose($file_config);


        // Count the number of languages
        $file_lang = fopen($location_lang . 'languages.txt', 'r');
        $fileCount = 0;
        while (!feof($file_lang)) {
            $temp_lang = fgets($file_lang, 4096);
            $fileCount++;
        }
        fclose($file_lang);

        // See if the language chosen in the select form is in the languages.txt file
        $file_lang = fopen($location_lang . 'languages.txt', 'r');
        for ($i=0; $i<$fileCount; $i++) {
            $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
            if ($value_select == $temp_lang) {
                $value_select = $i;
                break;
            }
        }
        fclose($file_lang);

        $form->get('select_language')->setValue($value_select);
        //////////////////////// End bind \\\\\\\\\\\\\\\\\\\\\\\\\\

        ////////////////////// Bind checkbox \\\\\\\\\\\\\\\\\\\\\\\
        $file_login = fopen($location_mod . 'Diagnostic/src/Diagnostic/InputFilter/LoginFormFilter.php', 'r');
        while(!feof($file_login)) {
            $temp_login = fgets($file_login, 4096);
            if($temp_login == "                        'allow' => Hostname::ALLOW_DNS," . PHP_EOL){$temp_login = fgets($file_login, 4096); $value_checkbox = substr($temp_login, 40, -2); break;}
        }
        fclose($file_login);

        if ($value_checkbox == 'true') {$value_checkbox = 1;}
        else {$value_checkbox = 0;}

        $form->get('checkbox_mxCheck')->setValue($value_checkbox);
        //////////////////////// End bind \\\\\\\\\\\\\\\\\\\\\\\\\\

        //////////////////////// Bind text \\\\\\\\\\\\\\\\\\\\\\\\\\
        $file_encrypt = fopen('/var/www/diagnostic/config/autoload/local.php', 'r');
        while(!feof($file_encrypt)) {
            $temp_encrypt = fgets($file_encrypt, 4096);
            if($temp_encrypt == "    ]," . PHP_EOL){$temp_encrypt = fgets($file_encrypt, 4096); $value_text = substr($temp_encrypt, 25, -3); break;}
        }
        fclose($file_encrypt);

        $form->get('encryption_key')->setValue($value_text);
        //////////////////////// End bind \\\\\\\\\\\\\\\\\\\\\\\\\\

        if ($request->isPost()) {
            $form->setData($request->getPost());

            if(isset($_POST['submit'])) {
                $success_upload = 1;

                // See if the language chosen in the select form is in the languages.txt file
                $file_lang = fopen($location_lang . 'languages.txt', 'r');
                for ($i=0; $i<$fileCount; $i++) {
                    $temp_lang = fgets($file_lang, 4096);
                    if ($request->getPost('select_language') == $i) {
                        $temp = $temp_lang;
                    }
                }
                fclose($file_lang);

                // Search the default translation line
                $file_config = fopen($location_mod . 'Diagnostic/config/module.config.php', 'r');
                $fileCount = -1;
                while(!feof($file_config)) {
                    $temp_config = fgets($file_config, 4096);
                    $fileCount+=1;
                    if($temp_config == "    'translator' => [" . PHP_EOL){$num_line = $fileCount; break;}
                }
                fclose($file_config);

                // Change the default translation
                $file_config = fopen($location_mod . 'Diagnostic/config/module.config.php', 'r');
                $contents = fread($file_config, filesize($location_mod . 'Diagnostic/config/module.config.php'));
                fclose($file_config);
                $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                $contents[$num_line+1] = "        'locale' => '" . substr($temp, 0, -1) . "',"; // Change the default translation with the new one
                $contents = array_values($contents);
                $contents = implode(PHP_EOL, $contents);
                $file_config = fopen($location_mod . 'Diagnostic/config/module.config.php', 'w');
                fwrite($file_config, $contents); // Write the file with the new default translation
                fclose($file_config);

                $file_login = fopen($location_mod . 'Diagnostic/src/Diagnostic/InputFilter/LoginFormFilter.php', 'r');
                $fileCount = -1;
                while(!feof($file_login)) {
                    $temp_login = fgets($file_login, 4096);
                    $fileCount+=1;
                    if($temp_login == "                        'allow' => Hostname::ALLOW_DNS," . PHP_EOL){$num_line_login = $fileCount; break;}
                }
                fclose($file_login);

                $file_email = fopen($location_mod . 'Admin/src/Admin/InputFilter/EmailNotExistFilter.php', 'r');
                $fileCount = -1;
                while(!feof($file_email)) {
                    $temp_email = fgets($file_email, 4096);
                    $fileCount+=1;
                    if($temp_email == "                        'allow' => Hostname::ALLOW_DNS," . PHP_EOL){$num_line_email = $fileCount; break;}
                }
                fclose($file_email);

                $file_user = fopen($location_mod . 'Admin/src/Admin/InputFilter/UserFormFilter.php', 'r');
                $fileCount = -1;
                while(!feof($file_user)) {
                    $temp_user = fgets($file_user, 4096);
                    $fileCount+=1;
                    if($temp_user == "                        'allow' => Hostname::ALLOW_DNS," . PHP_EOL){$num_line_user = $fileCount; break;}
                }
                fclose($file_user);

                if($request->getPost('checkbox_mxCheck')) {
                    $mxCheck = 'true';
                }else {
                    $mxCheck = 'false';
                }

                // Change mxcheck 1st file
                $file_login = fopen($location_mod . 'Diagnostic/src/Diagnostic/InputFilter/LoginFormFilter.php', 'r');
                $contents = fread($file_login, filesize($location_mod . 'Diagnostic/src/Diagnostic/InputFilter/LoginFormFilter.php'));
                fclose($file_login);
                $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                $contents[$num_line_login+1] = "                        'useMxCheck' => " . $mxCheck . ","; // Change the mxcheck with the new one
                $contents = array_values($contents);
                $contents = implode(PHP_EOL, $contents);
                $file_login = fopen($location_mod . 'Diagnostic/src/Diagnostic/InputFilter/LoginFormFilter.php', 'w');
                fwrite($file_login, $contents); // Write the file with the new default mxcheck
                fclose($file_login);

                // Change mxcheck 2nd file
                $file_email = fopen($location_mod . 'Admin/src/Admin/InputFilter/EmailNotExistFilter.php', 'r');
                $contents = fread($file_email, filesize($location_mod . 'Admin/src/Admin/InputFilter/EmailNotExistFilter.php'));
                fclose($file_email);
                $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                $contents[$num_line_email+1] = "                        'useMxCheck' => " . $mxCheck . ","; // Change the mxcheck with the new one
                $contents = array_values($contents);
                $contents = implode(PHP_EOL, $contents);
                $file_email = fopen($location_mod . 'Admin/src/Admin/InputFilter/EmailNotExistFilter.php', 'w');
                fwrite($file_email, $contents); // Write the file with the new default mxcheck
                fclose($file_email);

                // Change mxcheck 3rd file
                $file_user = fopen($location_mod . 'Admin/src/Admin/InputFilter/UserFormFilter.php', 'r');
                $contents = fread($file_user, filesize($location_mod . 'Admin/src/Admin/InputFilter/UserFormFilter.php'));
                fclose($file_user);
                $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                $contents[$num_line_user+1] = "                        'useMxCheck' => " . $mxCheck . ","; // Change the mxcheck with the new one
                $contents = array_values($contents);
                $contents = implode(PHP_EOL, $contents);
                $file_user = fopen($location_mod . 'Admin/src/Admin/InputFilter/UserFormFilter.php', 'w');
                fwrite($file_user, $contents); // Write the file with the new default mxcheck
                fclose($file_user);

                $file_encrypt = fopen('/var/www/diagnostic/config/autoload/local.php', 'r');
                $fileCount = -1;
                while(!feof($file_encrypt)) {
                    $temp_encrypt = fgets($file_encrypt, 4096);
                    $fileCount+=1;
                    if($temp_encrypt == "    ]," . PHP_EOL){$num_line_encrypt = $fileCount; break;}
                }
                fclose($file_encrypt);

                // Change the encryption_key
                $file_encrypt = fopen('/var/www/diagnostic/config/autoload/local.php', 'r');
                $contents = fread($file_encrypt, filesize('/var/www/diagnostic/config/autoload/local.php'));
                fclose($file_encrypt);
                $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                $contents[$num_line_encrypt+1] = "    'encryption_key' => '" . $request->getPost('encryption_key') . "',"; // Change the encryption_key with the new one
                $contents = array_values($contents);
                $contents = implode(PHP_EOL, $contents);
                $file_encrypt = fopen('/var/www/diagnostic/config/autoload/local.php', 'w');
                fwrite($file_encrypt, $contents); // Write the file with the new encryption_key
                fclose($file_encrypt);
            }

            // Add a statistic
            if(isset($_POST['submit_stat'])) {
                // The number must be an integer between 0 and 100 or it is not valid
                if ($request->getPost('diagnosis_stat') == '' || $request->getPost('diagnosis_stat') < 0 || $request->getPost('diagnosis_stat') > 100 || !is_numeric($request->getPost('diagnosis_stat')) || !is_int($request->getPost('diagnosis_stat') + 0)) {
                    $error_res = 1;
                }else {
                    $success_upload2 = 1;

                    // Create a file if it does not exist
                    if (!file_exists($location_stat . $request->getPost('date') . '.txt')) {
                        $file_stat = fopen($location_stat . $request->getPost('date') . '.txt', 'w');
                        fputs($file_stat, '__diagnosis' . PHP_EOL);
                        for ($i=1; $i<=38; $i++) {fputs($file_stat, '__activity' . $i . PHP_EOL);}
                        fclose($file_stat);
                    }

                    $file_stat = fopen($location_stat . $request->getPost('date') . '.txt', 'r');
                    $contents = fread($file_stat, filesize($location_stat . $request->getPost('date') . '.txt'));
                    fclose($file_stat);
                    $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                    $contents[0] = explode(',', $contents[0]);
                    if (isset($contents[0][1])) {
                        $contents[0][1] += 1;
                        $contents[0][$contents[0][1]+1] = $request->getPost('diagnosis_stat');
                        $total = 0;
                        for ($j=0; $j<$contents[0][1]; $j++) {$total += $contents[0][$j+2];}
                        $average = intdiv($total, $contents[0][1]);
                        array_push($contents[0], $average);
                    }else {
                        array_push($contents[0], 1);
                        array_push($contents[0], $request->getPost('diagnosis_stat'));
                        array_push($contents[0], $request->getPost('diagnosis_stat'));
                    }
                    $contents[0] = implode(',', $contents[0]);
                    for ($i=1; $i<count($contents); $i++) {
                        $contents[$i] = explode(',', $contents[$i]);
                        if ($request->getPost('activity') == $contents[$i][0]) {
                            if (isset($contents[$i][1])) {
                                $contents[$i][1] += 1;
                                $contents[$i][$contents[$i][1]+1] = $request->getPost('diagnosis_stat');
                                $total = 0;
                                for ($j=0; $j<$contents[$i][1]; $j++) {$total += $contents[$i][$j+2];}
                                $average = intdiv($total, $contents[$i][1]);
                                array_push($contents[$i], $average);
                            }else {
                                array_push($contents[$i], 1);
                                array_push($contents[$i], $request->getPost('diagnosis_stat'));
                                array_push($contents[$i], $request->getPost('diagnosis_stat'));
                            }
                        }
                        $contents[$i] = implode(',', $contents[$i]);
                    }
                    $contents = array_values($contents);
                    $contents = implode(PHP_EOL, $contents);
                    $file_stat = fopen($location_stat . $request->getPost('date') . '.txt', 'w');
                    fwrite($file_stat, $contents);
                    fclose($file_stat);
                }
            }
        }
        //send to view
        return new ViewModel([
            'form' => $form,
            'error_res' => $error_res,
            'success_upload' => $success_upload,
            'success_upload2' => $success_upload2
        ]);
    }

    /**
     * Templates
     *
     * @return ViewModel
     */
    public function templatesAction()
    {
        $error_upload = 0; // File not valid
        $success_upload = 0; // File valid

        $request = $this->getRequest();
        $form = $this->get('adminTemplateForm');

        if ($request->isPost()) {
            $form->setData($request->getPost());

            $location_lang = '/var/www/diagnostic/language/';
            $file_lang = fopen($location_lang . 'languages.txt', 'r');
            for ($i=1; $i<$_SESSION['nb_lang']; $i++) {
                $temp_lang = substr(fgets($file_lang, 4096), 0, -1);

                // Download the template in the current language
                if (isset($_POST['dl'.$i])){
                    $file = '/var/www/diagnostic/data/resources/model_' . $temp_lang . '.docx';

                    if (filesize($file) != 0) {
                        header('Content-Description: File Transfer');
                        header('Content-Type: application/octet-stream');
                        header('Content-Disposition: attachment; filename=model_' . $temp_lang . '.docx');
                        header('Expires: 0');
                        header('Cache-Control: must-revalidate');
                        header('Pragma: public');
                        header('Content-Length: ' . filesize($file));
                        readfile($file);
                    }else {
                        $file = '/var/www/diagnostic/data/resources/model_' . $_SESSION['lang'] . '.docx';
                        header('Content-Description: File Transfer');
                        header('Content-Type: application/octet-stream');
                        header('Content-Disposition: attachment; filename=model_' . $temp_lang . '.docx');
                        header('Expires: 0');
                        header('Cache-Control: must-revalidate');
                        header('Pragma: public');
                        header('Content-Length: ' . filesize($file));
                        readfile($file);
                    }
                }
            }
            fclose($file_lang);

            // Upload the modified template
            if (isset($_POST['submit_file'])){
                $file_country = fopen($location_lang . 'code_country.txt', 'r');
                while(!feof($file_country)){
                    $temp_country = substr(fgets($file_country, 4096), 0, -1);
                    if($temp_country == substr($_FILES['file']['name'], 6, -5)){$valid_file = $temp_country; break;}
                    $valid_file = 'en';
                }
                fclose($file_country);

                if ($_FILES['file']['name'] == 'model_' . $valid_file . '.docx' && file_exists('/var/www/diagnostic/data/resources/' . $_FILES['file']['name'])) {

                    move_uploaded_file($_FILES['file']['tmp_name'], '/var/www/diagnostic/data/resources/' . $_FILES['file']['name']);

                    // Search the modified template
                    $file_lang = fopen($location_lang . 'languages.txt', 'r');
                    $num_line = -1;
                    while(!feof($file_lang)) {
                        $temp_lang = fgets($file_lang, 4096);
                        $num_line+=1;
                        if($temp_lang == substr($_FILES['file']['name'], 6, -5).PHP_EOL) {break;}
                    }
                    fclose($file_lang);

                    // Change the user mail for the modified template
                    $file_user = fopen('/var/www/diagnostic/module/Admin/config/users.txt', 'r');
                    $contents = fread($file_user, filesize('/var/www/diagnostic/module/Admin/config/users.txt'));
                    fclose($file_user);
                    $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                    $contents[$num_line] = $_SESSION['email']; // Change the user mail with the new one
                    $contents = array_values($contents);
                    $contents = implode(PHP_EOL, $contents);
                    $file_user = fopen('/var/www/diagnostic/module/Admin/config/users.txt', 'w');
                    fwrite($file_user, $contents); // Write the file with the new user mail
                    fclose($file_user);
                    $success_upload = 1;
                }else {
                    $error_upload = 1;
                }
            }
        }

        //send to view
        return new ViewModel([
            'form' => $form,
            'error_upload' => $error_upload,
            'success_upload' => $success_upload
        ]);
    }

    /**
     * Languages
     *
     * @return ViewModel
     */
    public function languagesAction()
    {
        $location_lang = '/var/www/diagnostic/language/';

        // Variable to display error message when adding or deleting an invalid language
        $error_lang_exist = 0; // The language already exist and can't be added
        $error_lang_add = 0; // The language doesn't exist and can't be deleted
        $error_lang_del = 0; // English language can't be deleted
        $error_lang_del2 = 0; // You can't delete a current used language
        $error_upload = 0; // File not valid
        $error_key = 0; // Avoid to have the same translation key in a file

        //retrieve questions
        $questionService = $this->get('questionService');
        $questions = $questionService->getBddQuestions();

        $form = $this->get('adminLanguageForm');
        $request = $this->getRequest();

        if ($request->isPost()) {
            $form->setData($request->getPost());

            // Don't reset the reference language when clicking a button
            if (isset($_POST['submit_export']) || isset($_POST['submit_file']) || isset($_POST['submit_lang_add']) || isset($_POST['submit_lang_del']) || isset($_POST['submit_lang_ref']) || isset($_POST['submit_all']) || isset($_POST['submit__dl_report'])) {
                $_SESSION['base_lang'] = 1;
            }

            // Go to translations
            $file = fopen($location_lang . 'en/translations.po', 'r');
            $nb_translation = 0;
            $fileCount = 3;
            while (!feof($file)) {
                $temp = fgets($file, 4096);
                if ($temp == PHP_EOL) {break;}
            }
            while (!feof($file)) {
                $temp = fgets($file, 4096);
                if ($fileCount == 3) {$nb_translation++; $fileCount=0;}
                $fileCount++;
            }
            fclose($file);

            // num_line_all is used to change all translations in 1 button
            $file = fopen($location_lang . 'en/translations.po', 'r');
            $num_line_all = -1;
            while (!feof($file)) {
                $temp = fgets($file, 4096);
                $num_line_all++;
                if ($temp == PHP_EOL) {$num_line_all+=2; break;}
            }
            fclose($file);

            // Search the translation key in order to know the translation to change or delete
            $file_lang = fopen($location_lang . 'languages.txt', 'r');
            for ($j=1; $j<$_SESSION['nb_lang']; $j++) {
                $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
                if ($_SESSION['lang'] == $temp_lang) {

                    for ($i=1; $i<=$nb_translation; $i++){

                        // Action to modify translation
                        if (isset($_POST['mod'.$i])){
                            $_SESSION['base_lang'] = 1; // Don't change translation ref when modifying translation

                            // Search for the line of the translation key to modify
                            $file = fopen($location_lang . $temp_lang . '/translations.po', 'r');
                            $fileCount = -1;
                            $num_line = 0;
                            while (!feof($file)) {
                                $temp = fgets($file, 4096);
                                $fileCount++;
                                if(substr($temp, 7, -2) == $_SESSION['key_' . $temp_lang][$i]){
                                    $num_line = $fileCount;
                                }
                            }
                            fclose($file);

                            // Modify the translation
                            $file = fopen($location_lang . $temp_lang . '/translations.po', 'r');
                            $contents = fread($file, filesize($location_lang . $temp_lang .  '/translations.po'));
                            fclose($file);
                            $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                            $contents[$num_line+1] = 'msgstr "' . $request->getPost('translation'.$i) . '"'; // Change the translation with the new one
                            $contents = array_values($contents);
                            $contents = implode(PHP_EOL, $contents);
                            $file = fopen($location_lang . $temp_lang . '/translations.po', 'w');
                            fwrite($file, $contents); // Write the file with the new translation
                            fclose($file);

                            shell_exec('msgfmt ' . $location_lang . $temp_lang . '/translations.po -o ' . $location_lang . $temp_lang . '/translations.mo');
                        }

                        // Action to delete translation
                        if (isset($_POST['del'.$i])){

                            // Search for the line of the translation key to delete
                            $file = fopen($location_lang . $temp_lang . '/translations.po', 'r');
                            $fileCount = -1;
                            $num_line3 = 0;
                            while (!feof($file)) {
                                $temp = fgets($file, 4096);
                                $fileCount++;
                                if(substr($temp, 7, -2) == $_SESSION['key_'  . $temp_lang][$i]){
                                    $num_line3 = $fileCount;
                                }
                            }
                            fclose($file);
                        }
                    }

                    // Action to modify all translations
                    if (isset($_POST['submit_all'])){
                        $file = fopen($location_lang . $temp_lang . '/translations.po', 'r');
                        $contents = fread($file, filesize($location_lang . $temp_lang . '/translations.po'));
                        fclose($file);
                        $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                        for ($i=1; $i<=$nb_translation; $i++){
                            $contents[$num_line_all] = 'msgstr "' . $request->getPost('translation'.$i) . '"'; // Change the translation with the new one
                            $num_line_all+=3;
                        }
                        $contents = array_values($contents);
                        $contents = implode(PHP_EOL, $contents);
                        $file = fopen($location_lang . $temp_lang . '/translations.po', 'w');
                        fwrite($file, $contents); // Write the file with the new translation
                        fclose($file);

                        shell_exec('msgfmt ' . $location_lang . $temp_lang . '/translations.po -o ' . $location_lang . $temp_lang . '/translations.mo');
                    }
                }

                // Change reference language thanks to the session value
                if (isset($_POST['submit_lang_ref'])){
                    if ($request->getPost('language_ref') == $j-1) {
                        $_SESSION['change_language'] = $temp_lang;
                    }
                }
            }
            fclose($file_lang);

            // Delete translation
            $file_lang = fopen($location_lang . 'languages.txt', 'r');
            for ($j=1; $j<$_SESSION['nb_lang']; $j++) {
                $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
                for ($i=1; $i<=$nb_translation; $i++){
                    if (isset($_POST['del'.$i])){
                        $_SESSION['base_lang'] = 1;

                        $file = fopen($location_lang . $temp_lang . '/translations.po', 'r');
                        $contents = fread($file, filesize($location_lang . $temp_lang . '/translations.po'));
                        fclose($file);
                        $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                        unset($contents[$num_line3-1]); // Delete the line break
                        unset($contents[$num_line3]); // Delete the translation key
                        unset($contents[$num_line3+1]); // Delete the translation
                        $contents = array_values($contents);
                        $contents = implode(PHP_EOL, $contents);
                        $file = fopen($location_lang . $temp_lang . '/translations.po', 'w');
                        fwrite($file, $contents); // Write the file with the new translation
                        fclose($file);

                        shell_exec('msgfmt ' . $location_lang . $temp_lang . '/translations.po -o ' . $location_lang . $temp_lang . '/translations.mo');
                    }
                }
            }
            fclose($file_lang);

            // Add a language
            if (isset($_POST['submit_lang_add'])) {
                // Count the number of available languages
                $file_country = fopen($location_lang . 'code_country.txt', 'r');
                $fileCount = 0;
                while (!feof($file_country)) {
                    $temp_country = fgets($file_country, 4096);
                    $fileCount++;
                }
                fclose($file_country);

                // See if the language chosen in the select form is in the code_country file
                $file_country = fopen($location_lang . 'code_country.txt', 'r');
                for ($i=0; $i<$fileCount; $i++) {
                    $temp_country = substr(fgets($file_country, 4096), 0, -1);
                    if ($request->getPost('add_language') == $i) {
                        $temp = $temp_country;
                        $file_temp = fopen($location_lang . 'languages.txt', 'a+');
                        while (!feof($file_temp)) {
                            $temp_country = substr(fgets($file_temp, 4096), 0, -1);
                            if ($temp_country == $temp) {$error_lang_exist=1; break;}
                        }

                        if ($error_lang_exist == 0) {
                            fputs($file_temp, $temp.PHP_EOL);

                            // Creation file for the new language
                            mkdir($location_lang . $temp, 0700);

                            // Creation translations file by copying the beginning of the english file
                            $new_file = fopen($location_lang . $temp . '/translations.po', 'a+');
                            $en_file = fopen($location_lang . 'en/translations.po', 'r');
                            while (!feof($en_file)) {
                                $en_temp = fgets($en_file, 4096);
                                fputs($new_file, $en_temp);
                                if ($en_temp == PHP_EOL) {$en_temp = fgets($en_file, 4096); break;}
                            }
                            $fileCount = 1;
                            while (!feof($en_file)) {
                                if ($fileCount == 1) {fputs($new_file, $en_temp);}
                                elseif ($fileCount == 2) {fputs($new_file, 'msgstr ""');}
                                else {fputs($new_file, PHP_EOL); fputs($new_file, PHP_EOL); $fileCount = 0;}
                                $en_temp = fgets($en_file, 4096);
                                $fileCount++;
                            }
                            fputs($new_file, PHP_EOL);
                            fclose($en_file);
                            fclose($new_file);

                            shell_exec('msgfmt ' . $location_lang . $temp . '/translations.po -o ' . $location_lang . $temp . '/translations.mo');

                            // Creation questions file by copying the beginning of the english file
                            $new_file = fopen($location_lang . $temp . '/questions.po', 'a+');
                            $en_file = fopen($location_lang . 'en/questions.po', 'r');
                            while (!feof($en_file)) {
                                $en_temp = fgets($en_file, 4096);
                                fputs($new_file, $en_temp);
                                if ($en_temp == PHP_EOL) {$en_temp = fgets($en_file, 4096); break;}
                            }
                            $fileCount = 1;
                            while (!feof($en_file)) {
                                if ($fileCount == 1) {fputs($new_file, $en_temp);}
                                elseif ($fileCount == 2) {fputs($new_file, 'msgstr ""');}
                                else {fputs($new_file, PHP_EOL); fputs($new_file, PHP_EOL); $fileCount = 0;}
                                $en_temp = fgets($en_file, 4096);
                                $fileCount++;
                            }
                            fputs($new_file, PHP_EOL);
                            fclose($en_file);
                            fclose($new_file);

                            shell_exec('msgfmt ' . $location_lang . $temp . '/questions.po -o ' . $location_lang . $temp . '/questions.mo');

                            // Creation categories file by copying the beginning of the english file
                            $new_file = fopen($location_lang . $temp . '/categories.po', 'a+');
                            $en_file = fopen($location_lang . 'en/categories.po', 'r');
                            while (!feof($en_file)) {
                                $en_temp = fgets($en_file, 4096);
                                fputs($new_file, $en_temp);
                                if ($en_temp == PHP_EOL) {$en_temp = fgets($en_file, 4096); break;}
                            }
                            $fileCount = 1;
                            while (!feof($en_file)) {
                                if ($fileCount == 1) {fputs($new_file, $en_temp);}
                                elseif ($fileCount == 2) {fputs($new_file, 'msgstr ""');}
                                else {fputs($new_file, PHP_EOL); fputs($new_file, PHP_EOL); $fileCount = 0;}
                                $en_temp = fgets($en_file, 4096);
                                $fileCount++;
                            }
                            fputs($new_file, PHP_EOL);
                            fclose($en_file);
                            fclose($new_file);

                            shell_exec('msgfmt ' . $location_lang . $temp . '/categories.po -o ' . $location_lang . $temp . '/categories.mo');

                            // Create the template
                            $file_template = fopen('/var/www/diagnostic/data/resources/model_' . $temp . '.docx', 'a+');
                            copy('/var/www/diagnostic/data/resources/model_en.docx', '/var/www/diagnostic/data/resources/model_' . $temp . '.docx');
                            fclose($file_template);

                            // Create the user mail for the template
                            $file_user = fopen('/var/www/diagnostic/module/Admin/config/users.txt', 'a+');
                            fputs($file_user, $_SESSION['email']);
                            fputs($file_user, PHP_EOL);
                            fclose($file_user);
                        }
                        fclose($file_temp);
                    }
                }
                fclose($file_country);
            }

            // Delete a language
            if (isset($_POST['submit_lang_del'])) {
                $error_lang_add = 1;

                $file_lang = fopen($location_lang . 'code_country.txt', 'r');
                $fileCount = 0;
                while (!feof($file_lang)) {
                    $temp_lang = fgets($file_lang, 4096);
                    $fileCount++;
                }
                fclose($file_lang);

                $file_lang = fopen($location_lang . 'code_country.txt', 'r');
                for ($i=0; $i<$fileCount; $i++) {
                    $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
                    if ($request->getPost('add_language') == $i) {
                        $temp = $temp_lang;
                        $file_temp = fopen($location_lang . 'languages.txt', 'r');
                        $num_line = -1;
                        while (!feof($file_temp)) {
                            $temp_lang = substr(fgets($file_temp, 4096), 0, -1);
                            $num_line++;
                            if ($temp_lang == $temp) {
                                $error_lang_add=2;
                                if($temp_lang == $_SESSION['lang']) {$error_lang_del2 = 1;}
                                break;
                            }
                        }
                        fclose($file_temp);

                        // Avoid to delete english language, which is used to create other languages
                        if ($temp_lang == 'en' . PHP_EOL) {$error_lang_del=1;}

                        if ($error_lang_add == 2 && $error_lang_del == 0 && $error_lang_del2 == 0) {

                            $file_temp = fopen($location_lang . 'languages.txt', 'r');
                            $contents = fread($file_temp, filesize($location_lang . 'languages.txt'));
                            fclose($file_temp);
                            $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                            unset($contents[$num_line]); // Delete the user
                            $contents = array_values($contents);
                            $contents = implode(PHP_EOL, $contents);
                            $file_temp = fopen($location_lang . 'languages.txt', 'w');
                            fwrite($file_temp, $contents); // Write the file without the deleted files
                            fclose($file_temp);

                            unlink($location_lang . $temp_lang . '/translations.po');
                            unlink($location_lang . $temp_lang . '/translations.mo');

                            unlink($location_lang . $temp_lang . '/questions.po');
                            unlink($location_lang . $temp_lang . '/questions.mo');

                            unlink($location_lang . $temp_lang . '/categories.po');
                            unlink($location_lang . $temp_lang . '/categories.mo');

                            rmdir($location_lang . $temp_lang);

                            // Delete the template if exist
                            if(file_exists('/var/www/diagnostic/data/resources/model_' . $temp_lang . '.docx')) {
                                unlink('/var/www/diagnostic/data/resources/model_' . $temp_lang . '.docx' );
                            }

                            // Delete the user email of the template deleted
                            $file_user = fopen('/var/www/diagnostic/module/Admin/config/users.txt', 'r');
                            $contents = fread($file_user, filesize('/var/www/diagnostic/module/Admin/config/users.txt'));
                            fclose($file_user);
                            $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                            unset($contents[$num_line]); // Delete the user email
                            $contents = array_values($contents);
                            $contents = implode(PHP_EOL, $contents);
                            $file_user = fopen('/var/www/diagnostic/module/Admin/config/users.txt', 'w');
                            fwrite($file_user, $contents); // Write the file without the deleted files
                            fclose($file_user);

                            // Put the default language to english
                            $file_config = fopen('/var/www/diagnostic/module/Diagnostic/config/module.config.php', 'r');
                            $fileCount = -1;
                            while(!feof($file_config)) {
                                $temp_config = fgets($file_config, 4096);
                                $fileCount+=1;
                                if($temp_config == "    'translator' => [" . PHP_EOL){$num_line = $fileCount; break;}
                            }
                            fclose($file_config);

                            // Change the default translation
                            $file_config = fopen('/var/www/diagnostic/module/Diagnostic/config/module.config.php', 'r');
                            $contents = fread($file_config, filesize('/var/www/diagnostic/module/Diagnostic/config/module.config.php'));
                            fclose($file_config);
                            $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                            $contents[$num_line+1] = "        'locale' => 'en',"; // Change the default translation with the new one
                            $contents = array_values($contents);
                            $contents = implode(PHP_EOL, $contents);
                            $file_config = fopen('/var/www/diagnostic/module/Diagnostic/config/module.config.php', 'w');
                            fwrite($file_config, $contents); // Write the file with the new default translation
                            fclose($file_config);
                        }
                    }
                }
                fclose($file_lang);
            }

            // Upload
            if (isset($_POST['submit_file'])) {

                if (!empty($_FILES['file']['tmp_name'])) {

                    $content = file_get_contents($_FILES['file']['tmp_name']);
                    $tab = json_decode($content, true);

                    // Verify is the file is correct
                    $error_file = 0;
                    $i = 1;
                    while (isset($tab[$i-1])) {
                        if (!isset($tab[$i-1]['translation']) || !isset($tab[$i-1]['translation_key'])) {$error_file = 1;}
                        $i++;
                    }
                    if ($error_file == 1) {$tab = '';}
                    else {
                        $nb_translations = 1;
                        while (isset($tab[$nb_translations-1])) {$nb_translations++;}
                        for ($i=1; $i<$nb_translations - 1; $i++) {
                            for ($j=$i+1; $j<=$nb_translations - 1; $j++) {
                                if ($tab[$i-1]['translation_key'] == $tab[$j-1]['translation_key']) {$error_key = 1;}
                            }
                        }
                    }

                    // Write the translation in the file
                    if ($tab != '' && $error_key == 0) {
                        rename($location_lang . $_SESSION['lang'] . '/translations.po', $location_lang . $_SESSION['lang'] . '/translations_temp.po');
                        $file_temp = fopen($location_lang . $_SESSION['lang'] . '/translations_temp.po', 'r');
                        $file = fopen($location_lang . $_SESSION['lang'] . '/translations.po', 'w');
                        while (!feof($file_temp)) {
                            $temp = fgets($file_temp, 4096);
                            if ($temp == PHP_EOL) {$temp = fgets($file_temp, 4096); break;}
                            fputs($file, $temp);
                        }

                        $i = 1;
                        while (isset($tab[$i-1])) {
                            fputs($file, PHP_EOL);
                            fputs($file, 'msgid "' . $tab[$i-1]['translation_key'] . '"');
                            fputs($file, PHP_EOL);
                            fputs($file, 'msgstr "' . $tab[$i-1]['translation'] . '"');
                            fputs($file, PHP_EOL);
                            $i++;
                        }
                        fclose($file_temp);
                        fclose($file);
                        unlink($location_lang . $_SESSION['lang'] . '/translations_temp.po');

                        // compile from po to mo
                        shell_exec('msgfmt ' . $location_lang . $_SESSION['lang'] . '/translations.po -o ' . $location_lang . $_SESSION['lang'] . '/translations.mo');
                        fclose($file_lang);

                        return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'languages']);
                    }elseif ($tab == '') {$error_upload = 1;}
                }else {$error_upload = 1;}
            }

            // Export
            if (isset($_POST['submit_export'])) {
                $file = fopen($location_lang . $_SESSION['lang'] . '/translations.po', 'r');
                // Go to translations
                while (!feof($file)) {
                    $temp = fgets($file, 4096);
                    if ($temp == PHP_EOL) {$temp = fgets($file, 4096); break;}
                }

                $translations = [];

                $i = 1;
                while (!feof($file)) {
                    $translations[$i]['translation_key'] = substr($temp, 7, -2);
                    $temp = fgets($file, 4096);
                    $translations[$i]['translation'] = substr($temp, 8, -2);
                    $temp = fgets($file, 4096);
                    $temp = fgets($file, 4096);
                    $i++;
                }
                fclose($file);

                // Encode in a file
                $fichier = fopen('/var/www/diagnostic/translations_' . $_SESSION['lang'] . '.json', 'w+');
                fwrite($fichier, json_encode(array_values($translations), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                fclose($fichier);

                // Ddl the file and delete it in the VM
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename=translations_' . $_SESSION['lang'] . '.json');
                header('Expires: 0');
                header('Cache-Control: must-revalidate');
                header('Pragma: public');
                header('Content-Length: ' . filesize('/var/www/diagnostic/translations_' . $_SESSION['lang'] . '.json'));
                readfile('/var/www/diagnostic/translations_' . $_SESSION['lang'] . '.json');
                unlink('/var/www/diagnostic/translations_' . $_SESSION['lang'] . '.json');
            }
        }

        // English language when refreshing the page
        if ($_SESSION['base_lang'] == 0) {
            $_SESSION['change_language'] = 'en';
        }

        //send to view
        return new ViewModel([
            'form' => $form,
            'questions' => $questions,
            'error_lang_exist' => $error_lang_exist,
            'error_lang_add' => $error_lang_add,
            'error_lang_del' => $error_lang_del,
            'error_lang_del2' => $error_lang_del2,
            'error_upload' => $error_upload,
            'error_key' => $error_key
        ]);
    }

    /**
     * Add question
     *
     * @return ViewModel
     */
    public function addQuestionAction()
    {
        $location_lang = '/var/www/diagnostic/language/';

        $questionService = $this->get('questionService');
        $questions = $questionService->getBddQuestions();
        $questions_max = count($questions);

        // Session value to know if the translation key already exist
        $_SESSION['erreur_exist'] = 0;

        // Things we need for the db
        $tabToGet = ['category_id', 'translation_key', 'threat', 'weight', 'blocking', 'csrf', 'submit'];

        $form = $this->get('adminQuestionForm');

        //form is post and valid
        $request = $this->getRequest();
        if ($request->isPost()) {
            $form->setData($request->getPost());

            // Determine if the translation key already exist
            $cmd='grep -c -w ' . $request->getPost('translation_key') . ' ' . $location_lang . 'en/questions.po';
            if(exec($cmd) != 0){ $_SESSION['erreur_exist'] = 1;}

            if ($form->isValid() && $_SESSION['erreur_exist'] == 0) {
                // Get the category label of the question to have an UID based on it
                $file = fopen($location_lang . 'en/categories.po', 'r');
                while (!feof($file)) {
                    $temp = fgets($file, 4096);
                    if($temp == 'msgid "' . $form->get('category_id')->getValueOptions('label')[$request->getPost('category_id')] . '"' . PHP_EOL){
                        $temp = fgets($file, 4096);
                        $categ = substr($temp, 8, -2);
                        break;
                    }
                }
                fclose($file);
                $hash = [];
                $hash['translation_en'] = $request->getPost('translation_en');
                $hash['category_translation'] = $categ;

                $formData = [];
                foreach ($tabToGet as $key) {
                    $formData[$key] = $form->getData()[$key];
                }

                $i = 1;
                foreach ($questions as $question) {
                    if (substr($question->getTranslationKey(), 10) > $i) {$i = substr($question->getTranslationKey(), 10);}
                }
                $formData['id'] = $i+1;

                $formData['uid'] = md5(serialize($hash));

                $questionService->create((array)$formData);
                $questionService->resetCache();

                // Add translation to the .po files.
                $file_lang = fopen($location_lang . 'languages.txt', 'r');
                for ($i=1; $i<$_SESSION['nb_lang']; $i++) {
                    $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
                    rename($location_lang . $temp_lang . '/questions.po', $location_lang . $temp_lang . '/questions_temp.po');
                    $file_temp = fopen($location_lang . $temp_lang . '/questions_temp.po', 'r');
                    $file = fopen($location_lang . $temp_lang . '/questions.po', 'w');
                    while (!feof($file_temp)) {
                        $temp = fgets($file_temp, 4096);
                        fputs($file, $temp);
                        if (substr($temp, 7, -2) == '__question' . $questions_max . 'help') {
                            $temp = fgets($file_temp, 4096);
                            fputs($file, $temp);
                            fputs($file, PHP_EOL);
                            fputs($file,  'msgid "' . $request->getPost('translation_key') . '"');
                            fputs($file, PHP_EOL);
                            fputs($file,  'msgstr "' . $request->getPost('translation_' . $temp_lang) . '"');
                            fputs($file, PHP_EOL);
                            fputs($file, PHP_EOL);
                            fputs($file,  'msgid "' . $request->getPost('translation_key') . 'help"');
                            fputs($file, PHP_EOL);
                            if($request->getPost('help_' . $temp_lang) == ''){
                                fputs($file,  'msgstr " "');
                            }
                            else{
                                fputs($file,  'msgstr "' . $request->getPost('help_' . $temp_lang) . '"');
                            }
                            fputs($file, PHP_EOL);
                        }
                    }
                    fclose($file_temp);
                    fclose($file);
                    unlink($location_lang . $temp_lang . '/questions_temp.po');

                    // compile from po to mo
                    shell_exec('msgfmt ' . $location_lang . $temp_lang . '/questions.po -o ' . $location_lang . $temp_lang . '/questions.mo');
                }
                fclose($file_lang);

                //redirect
                return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'questions']);
            }
        }

        //send to view
        return new ViewModel([
            'form' => $form,
            'questions' => $questions
        ]);
    }

    /**
     * Add category
     *
     * @return ViewModel
     */
    public function addCategoryAction()
    {
        $location_lang = '/var/www/diagnostic/language/';

        $categoryService = $this->get('categoryService');
        $categories = $categoryService->getBddCategories();
        $categories_max = count($categories);

        // Session value to know if the translation key already exist
        $_SESSION['erreur_exist'] = 0;

        $tabToGet = ['translation_key', 'csrf', 'submit'];

        $form = $this->get('adminCategoryForm');

        //form is post and valid
        $request = $this->getRequest();
        if ($request->isPost()) {
            $form->setData($request->getPost());

            // Determine if the translation key already exist
            $cmd='grep -c -w ' . $request->getPost('translation_key') . ' ' . $location_lang . 'en/categories.po';
            if(exec($cmd) != 0){ $_SESSION['erreur_exist'] = 1;}

            if ($form->isValid() && $_SESSION['erreur_exist'] == 0) {
                $hash = [];
                $hash['translation_en'] = $request->getPost('translation_en');

                $formData = [];
                foreach ($tabToGet as $key) {
                    $formData[$key] = $form->getData()[$key];
                }

                $i = 1;
                foreach ($categories as $category) {
                    if (substr($category->getTranslationKey(), 10) > $i) {$i = substr($category->getTranslationKey(), 10);}
                }
                $formData['id'] = $i+1;

                $formData['uid'] = md5(serialize($hash));

                $categoryService->create((array)$formData);
                $categoryService->resetCache();

                // Add translation to the .po files.
                $file_lang = fopen($location_lang . 'languages.txt', 'r');
                for ($i=1; $i<$_SESSION['nb_lang']; $i++) {
                    $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
                    rename($location_lang . $temp_lang . '/categories.po', $location_lang . $temp_lang . '/categories_temp.po');
                    $file_temp = fopen($location_lang . $temp_lang . '/categories_temp.po', 'r');
                    $file = fopen($location_lang . $temp_lang . '/categories.po', 'w');
                    while (!feof($file_temp)) {
                        $temp = fgets($file_temp, 4096);
                        fputs($file, $temp);
                        if (substr($temp, 7, -2) == '__category' . $categories_max) {
                            $temp = fgets($file_temp, 4096);
                            fputs($file, $temp);
                            fputs($file, PHP_EOL);
                            fputs($file,  'msgid "' . $request->getPost('translation_key') . '"');
                            fputs($file, PHP_EOL);
                            fputs($file,  'msgstr "' . $request->getPost('translation_' . $temp_lang) . '"');
                            fputs($file, PHP_EOL);
                        }
                    }
                    fclose($file_temp);
                    fclose($file);
                    unlink($location_lang . $temp_lang . '/categories_temp.po');

                    // compile from po to mo
                    shell_exec('msgfmt ' . $location_lang . $temp_lang . '/categories.po -o ' . $location_lang . $temp_lang . '/categories.mo');
                }
                fclose($file_lang);

                //redirect
                return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'categories']);
            }
        }

        //send to view
        return new ViewModel([
            'form' => $form,
            'categories' => $categories
        ]);
    }

    /**
     * Add translation
     *
     * @return ViewModel
     */
    public function addTranslationAction()
    {
        $location_lang = '/var/www/diagnostic/language/';

        // Session value to know if the translation key already exist
        $_SESSION['erreur_exist'] = 0;

        $form = $this->get('adminAddTranslationForm');

        //form is post and valid
        $request = $this->getRequest();
        if ($request->isPost()) {
            $form->setData($request->getPost());

            // Determine if the translation key already exist
            $cmd='grep -c -w ' . $request->getPost('translation_key') . ' ' . $location_lang . 'en/translations.po';
            if(exec($cmd) != 0){ $_SESSION['erreur_exist'] = 1;}

            if ($form->isValid() && $_SESSION['erreur_exist'] == 0) {

                // Add translation to the .po files.
                $file_lang = fopen($location_lang . 'languages.txt', 'r');
                for ($i=1; $i<$_SESSION['nb_lang']; $i++) {
                    $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
                    $file = fopen($location_lang . $temp_lang . '/translations.po', 'a+');
                    fputs($file, PHP_EOL);
                    fputs($file,  'msgid "' . $request->getPost('translation_key') . '"');
                    fputs($file, PHP_EOL);
                    fputs($file,  'msgstr "' . $request->getPost('translation_' . $temp_lang) . '"');
                    fputs($file, PHP_EOL);
                    fclose($file);

                    // compile from po to mo
                    shell_exec('msgfmt ' . $location_lang . $temp_lang . '/translations.po -o ' . $location_lang . $temp_lang . '/translations.mo');
                }
                fclose($file_lang);

                //redirect
                return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'languages']);
            }
        }

        //send to view
        return new ViewModel([
            'form' => $form
        ]);
    }

    /**
     * Modify Question
     *
     * @return \Zend\Http\Response|ViewModel
     * @throws \Exception
     */
    public function modifyQuestionAction()
    {
        $location_lang = '/var/www/diagnostic/language/';

        // Session value to know if the translation key already exist
        $_SESSION['erreur_exist'] = 0;

        $tabToGet = ['category_id', 'translation_key', 'threat', 'weight', 'blocking', 'csrf', 'submit'];

        $id = $this->getEvent()->getRouteMatch()->getParam('id');

        if (is_null($id)) {
            throw new \Exception('Question not exist');
        }

        $form = $this->get('adminQuestionForm');

        $form->get('submit')->setValue('__modify');

        $questionService = $this->get('questionService');
        $questions = $questionService->getBddQuestions();

        if (!isset($_POST['translation_key'])) { // Only bind one at the beginning
            $form->bind($questions[$id]);
        }

        // Display the current value of the translation in the form-text (all languages)
        $file_lang = fopen($location_lang . 'languages.txt', 'r');
        for ($i=1; $i<$_SESSION['nb_lang']; $i++) {
            $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
            $file = fopen($location_lang . $temp_lang . '/questions.po', 'r');
            while (!feof($file)) { // Read the file
                $temp = fgets($file, 4096); // Variable which contains one by one lines of the file
                // This condition determines where the translation key is in the file, and put its translation in a session variable
                if($temp == 'msgid "' . $questions[$id]->getTranslationKey() . '"' . PHP_EOL){$_SESSION['value_' . $temp_lang] = fgets($file, 4096);}
                if($temp == 'msgid "' . $questions[$id]->getTranslationKey() . 'help"' . PHP_EOL){$_SESSION['value_' . $temp_lang . '_help'] = fgets($file, 4096);}
            }
            fclose($file);
            $_SESSION['value_' . $temp_lang] = substr($_SESSION['value_' . $temp_lang], 8, -2);
            $_SESSION['value_' . $temp_lang . '_help'] = substr($_SESSION['value_' . $temp_lang . '_help'], 8, -2);
        }
        fclose($file_lang);

        //form is post and valid
        $request = $this->getRequest();

        if ($request->isPost()) {

            $form->setData($request->getPost());

            // Determine if the translation key already exist
            $cmd='grep -c -w ' . $request->getPost('translation_key') . ' ' . $location_lang . 'en/questions.po';
            if(exec($cmd) != 0){ $_SESSION['erreur_exist'] = 1;}
            // If the translation key is the same than the current one, there is no error. Happens when you only want to change translations
            if($request->getPost('translation_key') == $questions[$id]->getTranslationKey()){$_SESSION['erreur_exist'] = 0;}

            if ($form->isValid() && $_SESSION['erreur_exist'] == 0) {
                // Get the category label of the question to have an UID based on it
                $file = fopen($location_lang . 'en/categories.po', 'r');
                while (!feof($file)) {
                    $temp = fgets($file, 4096);
                    if($temp == 'msgid "' . $form->get('category_id')->getValueOptions('label')[$request->getPost('category_id')] . '"' . PHP_EOL){
                        $temp = fgets($file, 4096);
                        $categ = substr($temp, 8, -2);
                        break;
                    }
                }
                fclose($file);
                $hash = [];
                $hash['translation_en'] = $request->getPost('translation_en');
                $hash['category_translation'] = $categ;

                $formData = [];
                foreach ($tabToGet as $key) {
                    $formData[$key] = $form->getData()[$key];
                }
                $formData['uid'] = md5(serialize($hash));

                $questionService->update($id, (array)$formData);
                $questionService->resetCache();

                // Create variables which will determine where to delete previous information in the translation files
                $file_lang = fopen($location_lang . 'languages.txt', 'r');
                for ($i=1; $i<$_SESSION['nb_lang']; $i++) {
                    $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
                    $fileCount = -1; // Variable to determine the position of the current line
                    $num_line = 0;
                    $file = fopen($location_lang . $temp_lang . '/questions.po', 'r');
                    while (!feof($file)) {
                        $temp = fgets($file, 4096);
                        $fileCount++;
                        if($temp == 'msgid "' . $questions[$id]->getTranslationKey() . '"' . PHP_EOL){$num_line = $fileCount; break;}
                    }
                    fclose($file);

                    // Rewrite the new translations
                    rename($location_lang . $temp_lang . '/questions.po', $location_lang . $temp_lang . '/questions_temp.po');
                    $file_temp = fopen($location_lang . $temp_lang . '/questions_temp.po', 'r');
                    $file = fopen($location_lang . $temp_lang . '/questions.po', 'w');
                    while (!feof($file_temp)) {
                        $temp = fgets($file_temp, 4096);
                        fputs($file, $temp);
                        if (substr($temp, 7, -2) == $questions[$id]->getTranslationKey() . 'help') {
                            $temp = fgets($file_temp, 4096);
                            fputs($file, $temp);
                            fputs($file, PHP_EOL);
                            fputs($file,  'msgid "' . $request->getPost('translation_key') . '"');
                            fputs($file, PHP_EOL);
                            fputs($file,  'msgstr "' . $request->getPost('translation_' . $temp_lang) . '"');
                            fputs($file, PHP_EOL);
                            fputs($file, PHP_EOL);
                            fputs($file,  'msgid "' . $request->getPost('translation_key') . 'help"');
                            fputs($file, PHP_EOL);
                            if($request->getPost('help_' . $temp_lang) == ''){
                                fputs($file,  'msgstr " "');
                            }
                            else{
                                fputs($file,  'msgstr "' . $request->getPost('help_' . $temp_lang) . '"');
                            }
                            fputs($file, PHP_EOL);
                        }
                    }
                    fclose($file_temp);
                    fclose($file);
                    unlink($location_lang . $temp_lang . '/questions_temp.po');

                    // Open the translation files and delete previous questions in order to add them with changes
                    $file = fopen($location_lang . $temp_lang . '/questions.po', 'r');
                    $contents = fread($file, filesize($location_lang . $temp_lang . '/questions.po'));
                    fclose($file);
                    $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                    unset($contents[$num_line-1]); // Delete the line break
                    unset($contents[$num_line]); // Delete the translation key
                    unset($contents[$num_line+1]); // Delete the translation
                    unset($contents[$num_line+2]); // Delete the line break
                    unset($contents[$num_line+3]); // Delete the help translation key
                    unset($contents[$num_line+4]); // Delete the help translation
                    $contents = array_values($contents);
                    $contents = implode(PHP_EOL, $contents);
                    $file = fopen($location_lang . $temp_lang . '/questions.po', 'w');
                    fwrite($file, $contents); // Write the file without the deleted files
                    fclose($file);

                    // compile from po to mo
                    shell_exec('msgfmt ' . $location_lang . $temp_lang . '/questions.po -o ' . $location_lang . $temp_lang . '/questions.mo');
                }
                fclose($file_lang);

                //redirect
                return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'questions']);
            }
        }

        //send to view
        return new ViewModel([
            'form' => $form,
            'id' => $id,
        ]);
    }

    /**
     * Modify Category
     *
     * @return \Zend\Http\Response|ViewModel
     * @throws \Exception
     */
    public function modifyCategoryAction()
    {
        $location_lang = '/var/www/diagnostic/language/';

        // Session value to know if the translation key already exist
        $_SESSION['erreur_exist'] = 0;

        $tabToGet = ['translation_key', 'csrf', 'submit'];

        $id = $this->getEvent()->getRouteMatch()->getParam('id');

        if (is_null($id)) {
            throw new \Exception('Category not exist');
        }

        $form = $this->get('adminCategoryForm');

        $form->get('submit')->setValue('__modify');

        $categoryService = $this->get('categoryService');
        $currentCategory = $categoryService->getCategoryById($id);

        foreach ($currentCategory as $category) {
            if ($category->getId() == $id) {
                $form->get('translation_key')->setValue($category->getTranslationKey());
                $cat = $category; // $cat equal to the category to modify
            }
        }

        // Display the current value of the translation in the form-text (all languages)
        $file_lang = fopen($location_lang . 'languages.txt', 'r');
        for ($i=1; $i<$_SESSION['nb_lang']; $i++) {
            $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
            $file = fopen($location_lang . $temp_lang . '/categories.po', 'r');
            while (!feof($file)) { // Read the file
                $temp = fgets($file, 4096); // Variable which contains one by one lines of the file
                // This condition determines where the translation key is in the file, and put its translation in a session variable
                if($temp == 'msgid "' . $cat->getTranslationKey() . '"' . PHP_EOL){$_SESSION['value_' . $temp_lang] = fgets($file, 4096);}
            }
            fclose($file);
            $_SESSION['value_' . $temp_lang] = substr($_SESSION['value_' . $temp_lang], 8, -2);
        }
        fclose($file_lang);

        //form is post and valid
        $request = $this->getRequest();
        if ($request->isPost()) {
            $form->setData($request->getPost());

            // Determine if the translation key already exist
            $cmd='grep -c -w ' . $request->getPost('translation_key') . ' ' . $location_lang . 'en/categories.po';
            if(exec($cmd) != 0){ $_SESSION['erreur_exist'] = 1;}
            // If the translation key is the same than the current one, there is no error. Happens when you only want to change translations
            if($request->getPost('translation_key') == $cat->getTranslationKey()){$_SESSION['erreur_exist'] = 0;}

            if ($form->isValid() && $_SESSION['erreur_exist'] == 0) {
                $hash = [];
                $hash['translation_en'] = $request->getPost('translation_en');

                $formData = [];
                foreach ($tabToGet as $key) {
                    $formData[$key] = $form->getData()[$key];
                }
                $formData['uid'] = md5(serialize($hash));

                $categoryService->update($id, (array)$formData);
                $categoryService->resetCache();

                // Create variables which will determine where to delete previous information in the translation files
                $file_lang = fopen($location_lang . 'languages.txt', 'r');
                for ($i=1; $i<$_SESSION['nb_lang']; $i++) {
                    $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
                    $fileCount = -1; // Variable to determine the position of the current line
                    $num_line = 0;
                    $file = fopen($location_lang . $temp_lang . '/categories.po', 'r');
                    while (!feof($file)) {
                        $temp = fgets($file, 4096);
                        $fileCount++;
                        if($temp == 'msgid "' . $cat->getTranslationKey() . '"' . PHP_EOL){$num_line = $fileCount; break;}
                    }
                    fclose($file);

                    // Rewrite the new translations
                    rename($location_lang . $temp_lang . '/categories.po', $location_lang . $temp_lang . '/categories_temp.po');
                    $file_temp = fopen($location_lang . $temp_lang . '/categories_temp.po', 'r');
                    $file = fopen($location_lang . $temp_lang . '/categories.po', 'w');
                    while (!feof($file_temp)) {
                        $temp = fgets($file_temp, 4096);
                        fputs($file, $temp);
                        if (substr($temp, 7, -2) == $cat->getTranslationKey()) {
                            $temp = fgets($file_temp, 4096);
                            fputs($file, $temp);
                            fputs($file, PHP_EOL);
                            fputs($file,  'msgid "' . $request->getPost('translation_key') . '"');
                            fputs($file, PHP_EOL);
                            fputs($file,  'msgstr "' . $request->getPost('translation_' . $temp_lang) . '"');
                            fputs($file, PHP_EOL);
                        }
                    }
                    fclose($file_temp);
                    fclose($file);
                    unlink($location_lang . $temp_lang . '/categories_temp.po');

                    // Open the translation files and delete previous questions in order to add them with changes.
                    $file = fopen($location_lang . $temp_lang . '/categories.po', 'r');
                    $contents = fread($file, filesize($location_lang . $temp_lang . '/categories.po'));
                    fclose($file);
                    $contents = explode(PHP_EOL, $contents); // PHP_EOL equals to /n in Linux
                    unset($contents[$num_line-1]); // Delete the line break
                    unset($contents[$num_line]); // Delete the translation key
                    unset($contents[$num_line+1]); // Delete the translation
                    $contents = array_values($contents);
                    $contents = implode(PHP_EOL, $contents);
                    $file = fopen($location_lang . $temp_lang . '/categories.po', 'w');
                    fwrite($file, $contents); // Write the file without the deleted files
                    fclose($file);

                    // compile from po to mo
                    shell_exec('msgfmt ' . $location_lang . $temp_lang . '/categories.po -o ' . $location_lang . $temp_lang . '/categories.mo');
                }
                fclose($file_lang);

                //redirect
                return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'categories']);
            }
        }

        //send to view
        return new ViewModel([
            'form' => $form,
            'id' => $id,
        ]);
    }

    /**
     * Delete user
     *
     * @return \Zend\Http\Response
     * @throws \Exception
     */
    public function deleteUserAction()
    {
        //id user
        $id = $this->getEvent()->getRouteMatch()->getParam('id');

        //retrieve users
        $userService = $this->get('userService');
        $users = $userService->getUsers();
        $usersIds = [];
        foreach ($users as $user) {
            $usersIds[] = $user->getId();
        }

        //security
        if (!in_array($id, $usersIds)) {
            throw new \Exception('User not exist');
        }

        $userService = $this->get('userService');
        $userService->delete($id);

        //redirect
        return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'users']);
    }

    /**
     * Delete question
     *
     * @return \Zend\Http\Response
     * @throws \Exception
     */
    public function deleteQuestionAction()
    {
        $location_lang = '/var/www/diagnostic/language/';

        //id user
        $id = $this->getEvent()->getRouteMatch()->getParam('id');

        //retrieve bdd questions
        $questionService = $this->get('questionService');
        $questions = $questionService->getBddQuestions();
        $questionsIds = [];
        foreach ($questions as $question) {
            $questionsIds[] = $question->getId();
            if($question->getId() == $id){$cat = $question;}
        }

        //security
        if (!in_array($id, $questionsIds)) {
            throw new \Exception('Question not exist');
        }

        // Delete translations from the translation files
        $file_lang = fopen($location_lang . 'languages.txt', 'r');
        for ($i=1; $i<$_SESSION['nb_lang']; $i++) {
            $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
            $fileCount = -1;
            $num_line = 0;
            $file = fopen($location_lang . $temp_lang . '/questions.po', 'r');
            while (!feof($file)) {
                $temp = fgets($file, 4096);
                $fileCount++;
                if($temp == 'msgid "' . $cat->getTranslationKey() . '"' . PHP_EOL){$num_line = $fileCount;}
            }
            fclose($file);

            $file = fopen($location_lang . $temp_lang . '/questions.po', 'r');
            $contents = fread($file, filesize($location_lang . $temp_lang . '/questions.po'));
            fclose($file);
            $contents = explode(PHP_EOL, $contents);
            unset($contents[$num_line-1]);
            unset($contents[$num_line]);
            unset($contents[$num_line+1]);
            unset($contents[$num_line+2]);
            unset($contents[$num_line+3]);
            unset($contents[$num_line+4]);
            $contents = array_values($contents);
            $contents = implode(PHP_EOL, $contents);
            $file = fopen($location_lang . $temp_lang . '/questions.po', 'w');
            fwrite($file, $contents);
            fclose($file);

            shell_exec('msgfmt ' . $location_lang . $temp_lang . '/questions.po -o ' . $location_lang . $temp_lang . '/questions.mo');
        }
        fclose($file_lang);

        $questionService->delete($id);
        $questionService->resetCache();

        // Delete the question from the result
        $container = new Container('diagnostic');
        $result = ($container->offsetExists('result')) ? $container->result : [];

        unset($result[$id]);

        $container->result = $result;

        //redirect
        return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'questions']);
    }

    /**
     * Delete category
     *
     * @return \Zend\Http\Response
     * @throws \Exception
     */
    public function deleteCategoryAction()
    {
        $location_lang = '/var/www/diagnostic/language/';

        //id user
        $id = $this->getEvent()->getRouteMatch()->getParam('id');

        //retrieve bdd categories
        $categoryService = $this->get('categoryService');
        $categories = $categoryService->getBddCategories();
        $categoriesIds = [];
        foreach ($categories as $category) {
            $categoriesIds[] = $category->getId();
            if($category->getId() == $id){$cat = $category;}
        }

        //security
        if (!in_array($id, $categoriesIds)) {
            throw new \Exception('Category not exist');
        }

        // Delete the question from the result
        $container = new Container('diagnostic');
        $result = ($container->offsetExists('result')) ? $container->result : [];

        // Search questions linked to the category to delete them
        $questionService = $this->get('questionService');
        $questions = $questionService->getBddQuestions();
        foreach ($questions as $question) {
            if($question->getCategoryTranslationKey() == $cat->getTranslationKey()){
                $file_lang = fopen($location_lang . 'languages.txt', 'r');
                for ($i=1; $i<$_SESSION['nb_lang']; $i++) {
                    $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
                    $fileCount = -1;
                    $num_line = 0;
                    $file = fopen($location_lang . $temp_lang . '/questions.po', 'r');
                    while (!feof($file)) {
                        $temp = fgets($file, 4096);
                        $fileCount++;
                        if($temp == 'msgid "' . $question->getTranslationKey() . '"'.PHP_EOL){$num_line = $fileCount;}
                    }
                    fclose($file);

                    $file = fopen($location_lang . $temp_lang . '/questions.po', 'r');
                    $contents = fread($file, filesize($location_lang . $temp_lang . '/questions.po'));
                    fclose($file);
                    $contents = explode(PHP_EOL, $contents);
                    unset($contents[$num_line-1]);
                    unset($contents[$num_line]);
                    unset($contents[$num_line+1]);
                    unset($contents[$num_line+2]);
                    unset($contents[$num_line+3]);
                    unset($contents[$num_line+4]);
                    $contents = array_values($contents);
                    $contents = implode(PHP_EOL, $contents);
                    $file = fopen($location_lang . $temp_lang . '/questions.po', 'w');
                    fwrite($file, $contents);
                    fclose($file);

                    shell_exec('msgfmt ' . $location_lang . $temp_lang . '/questions.po -o ' . $location_lang . $temp_lang . '/questions.mo');
                }
                fclose($file_lang);

                unset($result[$question->getId()]);
            }
        }

        $container->result = $result;

        // See comments in the delete function above
        $file_lang = fopen($location_lang . 'languages.txt', 'r');
        for ($i=1; $i<$_SESSION['nb_lang']; $i++) {
            $temp_lang = substr(fgets($file_lang, 4096), 0, -1);
            $fileCount = -1;
            $num_line = 0;
            $file = fopen($location_lang . $temp_lang . '/categories.po', 'r');
            while (!feof($file)) {
                $temp = fgets($file, 4096);
                $fileCount++;
                if($temp == 'msgid "' . $cat->getTranslationKey() . '"' . PHP_EOL){$num_line = $fileCount;}
            }
            fclose($file);

            $file = fopen($location_lang . $temp_lang . '/categories.po', 'r');
            $contents = fread($file, filesize($location_lang . $temp_lang . '/categories.po'));
            fclose($file);
            $contents = explode(PHP_EOL, $contents);
            unset($contents[$num_line-1]);
            unset($contents[$num_line]);
            unset($contents[$num_line+1]);
            $contents = array_values($contents);
            $contents = implode(PHP_EOL, $contents);
            $file = fopen($location_lang . $temp_lang . '/categories.po', 'w');
            fwrite($file, $contents);
            fclose($file);
            shell_exec('msgfmt ' . $location_lang . $temp_lang . '/categories.po -o ' . $location_lang . $temp_lang . '/categories.mo');
        }
        fclose($file_lang);

        $categoryService->delete($id);
        $categoryService->resetCache();
        $questionService->resetCache();

        //redirect
        return $this->redirect()->toRoute('admin', ['controller' => 'index', 'action' => 'categories']);
    }
}
