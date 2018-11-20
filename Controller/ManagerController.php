<?php

namespace Artgris\Bundle\FileManagerBundle\Controller;

use Artgris\Bundle\FileManagerBundle\Event\FileManagerEvents;
use Artgris\Bundle\FileManagerBundle\Helpers\File;
use Artgris\Bundle\FileManagerBundle\Helpers\FileManager;
use Artgris\Bundle\FileManagerBundle\Twig\OrderExtension;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints\NotBlank;
use Twistor\FlysystemStreamWrapper;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
/**
 * @author Arthur Gribet <a.gribet@gmail.com>
 * @author Moumen ALSHAAR <moumen.ashaar@gmail.com>
 */
class ManagerController extends Controller
{
    const PROTOCOL = 's3';
    const FileManager_folder_name = 'file-manager';
    /**
     * @var FileManager
     */
    protected $fileManager;

    /**
     * @var Flysystem
     */
    protected $filesystem;

    /**
     * @Route("/", name="file_manager")
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function indexAction(Request $request)
    {
        $queryParameters = $request->query->all();
        $translator = $this->get('translator');
        $isJson = $request->get('json') ? true : false;
        if ($isJson) {
            unset($queryParameters['json']);
        }
        $session = $this->container->get('session');

        $fileManager = $this->newFileManager($queryParameters);

        $finderFiles = new Finder();

        // Folder search //
        $directoriesArbo = $this->retrieveSubDirectories($fileManager, DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR);

        $filesystem = $this->getFileSystem($queryParameters);

        FlysystemStreamWrapper::register(self::PROTOCOL, $filesystem);// start stream from the adapter folder

        $streamDir = self::PROTOCOL . PATH_SEPARATOR . DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR;

        // File search
        $finderFiles->in($streamDir);

        $regex = $fileManager->getRegex();

        //////sort the files/////////////
        $orderBy = $fileManager->getQueryParameter('orderby');
        $orderDESC = OrderExtension::DESC === $fileManager->getQueryParameter('order');
        if (!$orderBy) {
            $finderFiles->sort(function (\SplFileInfo $a, \SplFileInfo $b) {
                return trim($b->getExtension()) == false;
            });
        }

        switch ($orderBy) {
            case 'name':
                $finderFiles->sort(function (SplFileInfo $a, SplFileInfo $b) {
                    return strcmp(strtolower($b->getFilename()), strtolower($a->getFilename()));
                });
                break;
//            case 'date':
//                $finderFiles->sortByModifiedTime();//TODO .... FIX SORT FOR FOLDERS
//                break;
//            case 'size':
//                $finderFiles->sort(function (\SplFileInfo $a, \SplFileInfo $b) {
//                    return $a->getSize() - $b->getSize();//TODO .... FIX SORT FOR FOLDERS
//                });
//                break;
        }

        if ($fileManager->getTree()) {
            $finderFiles->files()->name($regex)->filter(function (SplFileInfo $file) {
                return $file->isReadable();
            });
        } else {
            $finderFiles->filter(function (SplFileInfo $file) use ($regex) {
                if ($file->isFile()) {
                    if (preg_match($regex, $file->getFilename())) {
                        return $file->isReadable();
                    }

                    return false;
                }

                return true;
            });
        }

        $formDelete = $this->createDeleteForm()->createView();
        $fileArray = [];
        foreach ($finderFiles->files() as $file) {
            $filePath = $this->getFileLink($fileManager->getCurrentRoute(), $file->getfileName());
            $newFile = new File($file, $this->get('translator'), $this->get('file_type_service'), $fileManager);
            $newFile->setFileLink($filePath);

            $fileArray[] = $newFile;
        }

        if ('dimension' === $orderBy) {
            usort($fileArray, function (File $a, File $b) {
                $aDimension = $a->getDimension();
                $bDimension = $b->getDimension();
                if ($aDimension && !$bDimension) {
                    return 1;
                }

                if (!$aDimension && $bDimension) {
                    return -1;
                }

                if (!$aDimension && !$bDimension) {
                    return 0;
                }

                return ($aDimension[0] * $aDimension[1]) - ($bDimension[0] * $bDimension[1]);
            });
        }

        if ($orderDESC) {
            $fileArray = array_reverse($fileArray);
        }

        $parameters = [
            'fileManager' => $fileManager,
            'fileArray' => $fileArray,
            'formDelete' => $formDelete,
        ];

        if ($isJson) {
            $fileList = $this->renderView('@ArtgrisFileManager/views/_manager_view.html.twig', $parameters);

            return new JsonResponse(['data' => $fileList, 'badge' => $finderFiles->count(), 'treeData' => $directoriesArbo]);
        }
        $parameters['treeData'] = json_encode($directoriesArbo);

        $form = $this->container->get('form.factory')->createNamedBuilder('rename')
            ->add('name', new TextType, [
                'constraints' => [
                    new NotBlank(),
                ],
                'label' => false,
                'data' => $translator->trans('input.default'),
            ])
            ->add('send', new SubmitType, [
                'attr' => [
                    'class' => 'btn btn-primary',
                ],
                'label' => $translator->trans('button.save'),
            ])
            ->getForm();

        /* @var Form $form */
        $form->handleRequest($request);
        /** @var Form $formRename */
        $formRename = $this->createRenameForm();

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $directory = $data['name'];

            $fileInfo = $this->findFile($filesystem, $directory);

            try {
                if (!$fileInfo['exist']) {
                    $filesystem->createDir($directory);
                }
                $session->getFlashBag()->add('success', $translator->trans('folder.add.success'));
            } catch (IOExceptionInterface $e) {
                $session->getFlashBag()->add('danger', $translator->trans('folder.add.danger', ['%message%' => $data['name']]));
            }

            $this->redirect($this->generateUrl('file_manager', $fileManager->getQueryParameters()));
        }
        $parameters['form'] = $form->createView();
        $parameters['formRename'] = $formRename->createView();

        return $this->render('@ArtgrisFileManager/manager.html.twig', $parameters);
    }

    /**
     * @Route("/rename/{fileName}", name="file_manager_rename")
     * @param Request $request
     * @param $fileName
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @throws \Exception
     */
    public function renameFileAction(Request $request, $fileName)
    {
        $translator = $this->get('translator');
        $queryParameters = $request->query->all();
        $formRename = $this->createRenameForm();
        $session = $this->container->get('session');
        $filesystem = $this->getFileSystem($queryParameters);

        /* @var Form $formRename */
        $formRename->handleRequest($request);
        if ($formRename->isSubmitted() && $formRename->isValid()) {
            $data = $formRename->getData();
            $extension = $data['extension'] ? '.'.$data['extension'] : '';
            $newfileName = $data['name'].$extension;
            if ($newfileName !== $fileName && isset($data['name'])) {
                $fileManager = $this->newFileManager($queryParameters);

                if (!$filesystem->has($fileName)) {
                    $session->getFlashBag()->add('danger', $translator->trans('file.renamed.unauthorized'));
                }
                else {
                    try {
                        $filesystem->rename($fileName, $newfileName);

                        $session->getFlashBag()->add('success', $translator->trans('file.renamed.success'));

                    } catch (IOException $exception) {
                        $session->getFlashBag()->add('danger', $translator->trans('file.renamed.danger'));
                    }
                }
            } else {
                $session->getFlashBag()->add('warning', $translator->trans('file.renamed.nochanged'));
            }
        }

        return $this->redirect($this->generateUrl('file_manager', $queryParameters));
    }

    /**
     * @Route("/upload/", name="file_manager_upload")
     * @param Request $request
     * @return Response
     * @throws \Exception
     */
    public function uploadFileAction(Request $request)
    {
        $queryParameters = $request->query->all();
        $fileManager = $this->newFileManager($queryParameters);
        $filesystem = $this->getFileSystem($queryParameters);
        $session = $this->container->get('session');

        $this->dispatch(FileManagerEvents::PRE_UPDATE);

        $response = [
            'files' => []
        ];
        if (count($request->files->all())) {

            foreach ($request->files->all() as $fileArray) {

                foreach ($fileArray as $file) {
                    $result = $exist = false;

                    if (in_array($mimeType = $file->getClientMimeType(), ['image/png', 'image/gif', 'image/jpeg', 'text/css', 'application/javascript'])) {

                        $exist = $filesystem->has($file->getClientOriginalName());

                        $result = $filesystem->put($file->getClientOriginalName(), file_get_contents($file->getPathname()));

                        $response['files'] = [$this->getResponse($filesystem , $file->getClientOriginalName())];

                    } else {
                        $session->getFlashBag()->add('danger', 'file.add.danger');
                    }

                    if($exist && $result) {
                        $this->createInvalidation($fileManager->getCurrentRoute(), $file->getClientOriginalName());
                    }
                }
            }
        }

        $this->dispatch(FileManagerEvents::POST_UPDATE, ['response' => &$response]);

        return new JsonResponse($response);
    }

    /**
     * @Route("/file/{fileName}", name="file_manager_file")
     * @param Request $request
     * @param $fileName
     * @return BinaryFileResponse
     * @throws \Exception
     */
    public function binaryFileResponseAction(Request $request, $fileName)
    {
        //TODO .... REMOVE IT
        $fileManager = $this->newFileManager($request->query->all());

        return $fileName;
    }

    /**
     * @Route("/delete/", name="file_manager_delete")
     * @param Request $request
     * @Method("DELETE")
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     * @throws \Exception
     */
    public function deleteAction(Request $request)
    {
        $form = $this->createDeleteForm();
        $form->handleRequest($request);
        $queryParameters = $request->query->all();
        $session = $this->container->get('session');
        if ($form->isSubmitted() && $form->isValid()) {
            // remove file
            $fileManager = $this->newFileManager($queryParameters);
            $filesystem = $this->getFileSystem($queryParameters);

            if (isset($queryParameters['delete'])) {
                $is_delete = false;

                foreach ($queryParameters['delete'] as $fileName) {
                    $fileInfo = $this->findFile($filesystem,$fileName);

                    if (!$fileInfo['exist']) {
                        $session->getFlashBag()->add('danger', 'file.deleted.danger');
                    } else {
                        $this->dispatch(FileManagerEvents::PRE_DELETE_FILE);
                        try {

                            $is_delete = $fileInfo['isFile'] ? $filesystem->delete($fileName) : $filesystem->deleteDir($fileName);

                        } catch (IOException $exception) {
                            $session->getFlashBag()->add('danger', 'file.deleted.unauthorized');
                        }
                        $this->dispatch(FileManagerEvents::POST_DELETE_FILE);
                    }
                }
                if ($is_delete) {
                    $session->getFlashBag()->add('success', 'file.deleted.success');
                }
                unset($queryParameters['delete']);
            }
        }

        return $this->redirect($this->generateUrl('file_manager', $queryParameters));
    }

    /**
     * @return Form|\Symfony\Component\Form\FormInterface
     */
    private function createDeleteForm()
    {
        return $this->createFormBuilder()
            ->setMethod('DELETE')
            ->add('DELETE', new SubmitType, [
                'attr' => [
                    'class' => 'btn btn-danger',
                ],
                'label' => 'button.delete.action',
            ])
            ->getForm();
    }

    /**
     * @return mixed
     */
    private function createRenameForm()
    {
        return $this->createFormBuilder()
            ->add('name', new TextType, [
                'constraints' => [
                    new NotBlank(),
                ],
                'label' => false,
            ])->add('extension', new HiddenType)
            ->add('send', new SubmitType, [
                'attr' => [
                    'class' => 'btn btn-primary',
                ],
                'label' => 'button.rename.action',
            ])
            ->getForm();
    }

    /**
     * @param FileManager $fileManager
     * @param $path
     * @param string $parent
     * @return array|null
     */
    private function retrieveSubDirectories(FileManager $fileManager, $path, $parent = DIRECTORY_SEPARATOR)
    {
        $parameters = $fileManager->getQueryParameters();

        if(isset($parameters['route'])){
            unset($parameters['route']);
        }

        $fs = $this->getFileSystem($parameters);
        $directories = $fs->listContents($path);

        $directoriesList = null;

        foreach ($directories as $directory) {
            if($directory['type'] == 'dir') {

                $subDirName = strrpos($directory['path'], DIRECTORY_SEPARATOR) ?
                    substr($directory['path'], strrpos($directory['path'], DIRECTORY_SEPARATOR) + 1) :
                    $directory['path'];

                $fileName = $parent.$subDirName;

                $queryParameters = $fileManager->getQueryParameters();
                $queryParameters['route'] = $fileName;
                $queryParametersRoute = $queryParameters;
                unset($queryParametersRoute['route']);

                $directoriesList[] = [
                    'text' => $subDirName,
                    'icon' => 'fa fa-folder-o',
                    'children' => $this->retrieveSubDirectories($fileManager, $directory['path'], $fileName.DIRECTORY_SEPARATOR),
                    'a_attr' => [
                        'href' => $fileName ? $this->generateUrl('file_manager', $queryParameters) : $this->generateUrl('file_manager', $queryParametersRoute),
                    ], 'state' => [
                        'selected' => $fileManager->getCurrentRoute() === $fileName,
                        'opened' => true,
                    ],
                ];
            }
        }

        return $directoriesList;
    }

    /**
     * Tree Iterator.
     * @param $path
     * @param $regex
     * @return int
     */
    private function retrieveFilesNumber($path, $regex)
    {
        $files = new Finder();
        $files->in($path)->files()->depth(0)->name($regex);

        return iterator_count($files);
    }

    /*
     * Base Path
     */
    private function getBasePath($queryParameters)
    {
        //TODO .... REMOVE IT
        $conf = $queryParameters['conf'];
        $managerConf = $this->container->getParameter('artgris_file_manager')['conf'];
        if (isset($managerConf[$conf]['dir'])) {
            return $managerConf[$conf];
        }

        if (isset($managerConf[$conf]['service'])) {
            $extra = isset($queryParameters['extra']) ? $queryParameters['extra'] : [];
            $conf = $this->get($managerConf[$conf]['service'])->getConf($extra);

            return $conf;
        }

        throw new \RuntimeException('Please define a "dir" or a "service" parameter in your config.yml');
    }

    /**
     * @return mixed
     */
    private function getKernelRoute()
    {
        return $this->container->getParameter('kernel.root_dir');//TODO .... REMOVE IT
    }

    /**
     * @param $queryParameters
     * @return FileManager
     * @throws \Exception
     */
    private function newFileManager($queryParameters)
    {
        if (!isset($queryParameters['conf'])) {
            throw new \RuntimeException('Please define a conf parameter in your route');
        }
        //TODO .... change the fileManager

        $webDir = $this->container->getParameter('artgris_file_manager')['web_dir'];

        $this->fileManager = new FileManager($queryParameters, $this->getBasePath($queryParameters), $this->getKernelRoute(), $this->get('router'), $webDir);

        return $this->fileManager;
    }

    protected function dispatch($eventName, array $arguments = [])
    {
        $arguments = array_replace([
            'filemanager' => $this->fileManager,
        ], $arguments);

        $subject = $arguments['filemanager'];
        $event = new GenericEvent($subject, $arguments);
        $this->get('event_dispatcher')->dispatch($eventName, $event);
    }

    /**
     * @param array $queryParameters
     * @return Flysystem $filesystem
     */
    protected function getFileSystem($queryParameters)
    {
        $filesystem = $this->container->get('awss3v3_filesystem');

        $basePathName = $this->container->get('aws_account_service')->getPathName();
        $fileManagerName = $basePathName . '-' . self::FileManager_folder_name;

        $filesystem->getAdapter()->setPathPrefix($basePathName);
        $filesystem->createDir($fileManagerName);
        $filesystem->getAdapter()->setPathPrefix($filesystem->getAdapter()->applyPathPrefix($fileManagerName));

        if(isset($queryParameters['route'])){
            $filesystem->getAdapter()->setPathPrefix($filesystem->getAdapter()->applyPathPrefix($queryParameters['route']));
            unset($queryParameters['route']);
        }
        return $filesystem;
    }

    /**
     * @param string $route
     * @param string $fileName
     * @return Flysystem $filesystem
     */
    protected function getFileLink($route , $fileName)
    {
        return $this->container->get('aws_account_service')
            ->getCloudFrontEndpoint($route, $fileName);
    }

    /**
     * @param string $fileName
     * @param Flysystem $filesystem
     * @return array $fileInfo
     */
    private function findFile($filesystem, $fileName)
    {
        $fileInfo = [
            'exist' => false,
            'isFile' => false,
            'isDir' => false
        ];

        foreach($filesystem->listContents() as $file){
            if($file['path'] == $fileName){
                $fileInfo = [
                    'exist' => true,
                    'isFile' => $file['type'] == 'file',
                    'isDir' => $file['type'] == 'dir'
                ];
            }
        }

        return $fileInfo;
    }

    /**
     * @param string $fileName
     * @param Flysystem $filesystem
     * @return array $fileInfo
     */
    private function getResponse($filesystem, $fileName)
    {
        $responseInfo = [];

        if($filesystem->has($fileName)){

            $responseInfo = [
                'name' => $fileName,
                'size' => $filesystem->getSize($fileName),
                'type' => $filesystem->getMimetype($fileName),
                'url' => $fileName,
                'deleteUrl' => '',
                'deleteType' => ''
            ];
        }

        return $responseInfo;
    }

    /**
     * @param string $routeName
     * @param string $fileName
     * @return Response
     */
    private function createInvalidation($routeName, $fileName)
    {
        $kernel = $this->get('kernel');
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $input = $this->container->get('aws_account_service')
            ->createCommandInput($routeName, $fileName, $kernel->getEnvironment());

        $output =  new NullOutput();
        $application->run($input, $output);

        return new Response('');
    }
}