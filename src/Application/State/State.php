<?php

namespace App\Application\State;

enum State: string
{
    case Start = 'Start';

    // Авторизация (Authorization)
    case Share_Contact = 'Поделиться контактом';


    // Обращения и заявки (Requests and Applications)
    case Request_Created = 'Создана заявка';
    case Finish_Request = 'Закрыть заявку';
    case Issue_AlReady_Exist = 'Признак наличия открытой заявки';


    // Прочие (Miscellaneous)
    case GoToBack = 'Назад';

    case Cut_By_QR_Code = 'Рез по QR-коду';


    // ======================= Из Hfx ===========================
    case Main_Menu = 'Главное меню';
    case Scan_Plotter_QR_Code = 'Сканировать QR код плоттера';
    case Enter_Plotter_Number_Manually = 'Укажите номер плоттера вручную';
    case Where_To_Find_Plotter_Number = 'Где посмотреть номер плоттера';
    case Write_Message = 'Написать сообщение';
    case Create_Request = 'Создать новую заявку';
    case Create_New = 'Создать новую';
    case Create_Request_Without_Auth = 'Создать без авторизации';
    case Open_Scanner = 'Открыть сканер';
    case Unlock_Cutter = "Разблокировать рез по чеку";
    case Check_Not_Found = 'Чек не найден';
    case Expected_Plotter_Number = 'Ожидается ввод номера плоттера';

    case Yes = 'Да';
    case No = 'Нет';
    case Return_To_Start = 'В начало';
    case Stay = 'Остаться';

    // Управляющие команды (Control Commands)
    case Start_Command = "/start";
    case Main_Command = "/main";
    case Close_Command = "/close";
    case Create_Command = "/create";
    case Delete_Command = "/delete";
    

    // Метод получения всех значений
    public static function getAllValues(): array
    {
        return array_map(fn($state) => $state->value, self::cases());
    }
}
