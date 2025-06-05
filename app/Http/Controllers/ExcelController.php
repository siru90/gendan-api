<?php
namespace App\Http\Controllers;

use App\Ok\SysError;
use Illuminate\Http\Request;
use stdClass;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelController extends Controller
{
    // 导入
    public function import(Request $request): \Illuminate\Http\JsonResponse
    {
        require_once dirname(dirname(dirname(__DIR__))). '/PHPExcel/Classes/PHPExcel.php';

        # 上传文件
        $file = $request->file('file');
        if (!$file) {
            return $this->renderErrorJson(SysError::PARAMETER_ERROR);
        }

        # 支持的文件扩展名
        if (!in_array($file->extension(), [
            'xls', 'xlsx',
        ])) {
            return $this->renderErrorJson(SysError::EXTENSION_ERROR);
        }
        $userId = \App\Http\Middleware\UserId::$user_id;
        $data = new stdClass();

        # 读取 Excel 文件
        $excelReader = \PHPExcel_IOFactory::createReaderForFile($file->path());
        $excelObj  = $excelReader->load($file->path());


        # 获取工作表
        $worksheet = $excelObj->getSheet(0);
        //var_dump($worksheet);die;

        # 获取行数和列数
        $lastRow = $worksheet->getHighestRow();
        $lastColumn = $worksheet->getHighestColumn();

        //var_dump($lastRow,$lastColumn);die;

        # 循环读取每行数据
        for ($row = 1; $row <= $lastRow; $row++) {
            # 从第一列开始读取数据
            for ($column = 'A'; $column <= $lastColumn; $column++) {
                $cellValue = $worksheet->getCell($column.$row)->getValue();
                echo $cellValue."\t";
            }
            echo "\n";
        }


        return $this->renderJson($data);
    }


    //导出
    public function export(Request $request)
    {

        $spreadsheet = new Spreadsheet();

// 获取当前活动的工作表
        $sheet = $spreadsheet->getActiveSheet();

// 给单元格设置值
        $sheet->setCellValue('A1', 'Hello World !');

// 创建一个写入器来写入Excel 2007文件（xlsx）
        $writer = new Xlsx($spreadsheet);

// 保存Excel 2007文件
        $fileName = storage_path().'/logs/hello_world.xlsx';

        //var_dump($fileName);die;
        $writer->save($fileName);

        echo "文件已创建: " . $fileName;
    }

}
