var onEdit = false
var editedButton = null
var startRow = null
var endRow = null
var prevStep = null
var actionList = null

//----------------------------------------------------------------------------------------------------------------------
// Законченные и протестированные
//----------------------------------------------------------------------------------------------------------------------
// Старторвая инициализация страницы
$('document').ready(function(){
    // Загрузка списка действий
    $.ajax({
        url: 'php/getActionList.php',
        type: 'GET',
        dataType: 'json',
        success: function (list) {
            actionList = list
            console.log(actionList)
        }
    })

    // Загрузка значений из БД для заполнения страницы при ее открытии
    $.ajax({
        url: 'php/getScreenList.php',
        type: 'GET',
        dataType: 'json',
        success: function (res) {
            $('.navigation-box').html('')
            $(res).each(function (ind, value) {
                $('.navigation-box').append('<div class="screenLabel" data-screen-number="'+value.screenID+'">'+value.screenName+'</div>')

                if (ind == 0)
                    $('.screenLabel').addClass('selected');
            })

            loadScreen(res[0].screenID)

            $('.navigation-box .screenLabel').click(switchScreen)
            $('nav button').click(function (){
                addNewScreen()
            })

            $("#editBtn").click(editON)                     // ok
            $("#saveBtn").click(saveEdit)                   // ok
            $("#cancelBtn").click(cancelEdit)               // ok
            $("#deleteBtn").click(deleteScreen)
            $("#addButton").click(addButton)                // ok

            createModals()                                  // ok
        }
    })
})

// Обработка нажатия на кнопку клавиатуры
// Выводит текст ответного сообщения и дает возможность перейти на ступень, к которой ведет кнопка
// Или создать данную ступень, если ее еще нет
let btnClick = function () {
    if (!onEdit) {
        let curScreen = $(".screenLabel.selected").attr("data-screen-number")
        let nextScreen = $(this).attr('data-next-screen')
        console.log(nextScreen)
        let curLabel = $(".screenLabel.selected").html()
        let nextLabel = $(".screenLabel[data-screen-number='"+nextScreen+"']").html()
        $("#button-go-next-step").parent().children('.ui-dialog-titlebar').children('.ui-dialog-title').html('Экран "' +curLabel+'"')

        let replyMessage = $(this).attr('data-reply')
        replyMessage = replyMessage.replace('\n', '<br>')
        $('#button-go-next-step p').html(replyMessage)

        if ($('.screenLabel[data-screen-number="'+nextScreen+'"]').length == 0) { // Если следующий экран еще не создан
            $("#ui-dialog-next-step").html('Следующий экран еще не создан')

            $("#button-go-next-step").dialog('option', 'buttons', {
                'Создать ступень': function (){
                    addNewScreen(nextScreen)
                    $(this).dialog('close')
                },
                'Отменить': function (){
                    $(this).dialog('close')
                }
            })
        } else { // Если следующий экран уже создан
            $("#ui-dialog-next-step").html('Следующий экран "'+nextLabel+'"')

            $("#button-go-next-step").dialog('option', 'buttons', {
                'Перейти': function (){
                    $(this).dialog('close')
                    $('.screenLabel[data-screen-number="'+nextScreen+'"]').click()
                },
                'Отменить': function (){
                    $(this).dialog('close')
                }
            })
        }

        $("#button-go-next-step").dialog("open")
    }
}

// Загружаем и заполняем страницу информацией заданного этапа
let loadScreen = function (screenID) {
    $.ajax({
        url: 'php/getScreen.php',
        type: 'GET',
        data: {'screenID': screenID},
        dataType: 'json',
        success: function (res) {
            $("#main h2").html(res.screenName)
            $("#screenMessage").val(res.screenMessage)
            $("#keyboard ul").remove()

            var rowCount = 1
            $("#keyboard #addButton").before('<ul></ul>')
            var curRow = $("#keyboard ul").last();

            $(res.keyboard).each(function (ind, value) {
                if (value.buttonRow > rowCount) {
                    rowCount++
                    $("#keyboard #addButton").before('<ul></ul>')
                    curRow = $("#keyboard ul").last();
                }

                curRow.append('<li data-next-screen="'+value.next_screen+'"'+'data-reply="'+value.reply+'">'+value.caption+'</li>')

                editBtnSize(curRow)
            })

            $("#keyboard ul li").click(btnClick)

            $("#keyboard ul li").dblclick(btnDblClick)      // ok
        }
    })
}

// Переключение ступени
let switchScreen = function () {
    // Если мы в режиме редактирования, то блокируем переход на другой этап
    if (onEdit)
        return

    $('.navigation-box .screenLabel').removeClass('selected')
    $(this).addClass('selected')

    let screenRequered = $(this).attr('data-screen-number')
    $("#main h2").html('Этап ' + screenRequered)

    loadScreen(screenRequered)
}

// Отменяем результаты редактирования
let cancelEdit = function(){
    editOFF()
    if (prevStep) {
        $('.screenLabel.selected').remove()
        prevStep.click()
        prevStep = null
    }
    else loadScreen($('.screenLabel.selected').attr('data-screen-number'))
}

// Создание нового экрана
let addNewScreen = function (screen_id = undefined) {
    // Если мы в режиме редактирования, то блокируем переход на другой этап
    if (onEdit)
        return

    if (screen_id == undefined)
        screen_id = Number($('.navigation-box div').last().attr('data-screen-number')) + 1

    prevStep = $(".navigation-box .selected")

    $('.navigation-box').append('<div class="screenLabel" data-screen-number="' + screen_id + '">Новый экран</div>')
    $('.navigation-box div').last().click(switchScreen)
    $('.navigation-box div').last().click()

    editON()
}

// Инициализация модального окна с информацией о кнопке
let initModalInfo = function (btn) {
    $('#button-info textarea').val($(btn).attr('data-reply'))
}

// Включение режима редактирвоания
let editON = function () {
    onEdit = true;

    $("#keyboard ul").sortable(sortableSettings);

    $('.screen-name-edit').show()
    $('#screenName').val($("#main h2").html())
    $("#screenMessage").addClass('onEdit')
    $("#keyboard ul").addClass('onEdit')

    $("#screenMessage").prop('readonly', false)

    $('#editBtn').hide()
    $('#saveBtn').show()
    $('#cancelBtn').show()
    $('#deleteBtn').show()
    $('#addButton').show()
}

// Выключаем режим редактирования
let editOFF = function () {
    onEdit = false

    $(".screen-name-edit").hide()

    $("#screenMessage").removeClass('onEdit')
    $("#keyboard ul").removeClass('onEdit')

    $("#screenMessage").prop('readonly', true)

    $('#editBtn').show()
    $('#saveBtn').hide()
    $('#cancelBtn').hide()
    $('#deleteBtn').hide()
    $('#addButton').hide()

    deleteEmptyRows()

    // Если было инициализированно создание новой ступени, но не доведенно до конца, то дальше тут делать нечего
    if (prevStep)
        return

    $("#keyboard ul").sortable('destroy')
}

// Удаление пустных рядов клавиатуры
let deleteEmptyRows = function () {
    $("#keyboard ul").each(function (ind, row) {
        if ($(row).children().length == 0)
            $(row).remove()
    })
}

// Подстройка размеров кнопок в ряду
// Передевать необходимо уже jquery объект – ряд, в котором необходимо переработать размеры кнопок
let editBtnSize = function (row) {
    var btnCount = row.children().length
    var kf = (btnCount - 1) * 10 / btnCount

    row.children().css('width', 'calc(100%/' + btnCount + ' - ' + kf + 'px)')
}

// Настройки для создания связанные группируемых списков
let sortableSettings = {
    connectWith: '#keyboard ul',
    start: function (event, ui) {
        startRow = event.target
    },
    update: function (event, ui) {
        endRow = event.target

        if (startRow != endRow) {
            var startLength = $(startRow).children().length

            if (startLength == 0)
                startRow.remove()
            else editBtnSize($(startRow))
            editBtnSize($(endRow))
        }
    }
}

// Добавление новой кнопки в клавиатуру
// Каждая новая кнопка добавляется в последний ряд, в котором есть место, в противном случае создаться новый
let addButton = function(){
    var curRow = $("#keyboard ul").last()
    var lastRowBtnCount = $(curRow).children().length
    var nextScreen = $('.screenLabel').last().attr('data-screen-number')
    nextScreen++

    if (lastRowBtnCount < 4) {
        $(curRow).append('<li data-next-screen="'+nextScreen+'">Новая кнопка</li>')
        editBtnSize(curRow)

        $("#keyboard #addButton").before("<ul class='onEdit'></ul>")
    } else {
        $("#keyboard #addButton").before("<ul class='onEdit'></ul>")
        $("#keyboard ul").last().append('<li data-next-step="'+nextScreen+'>Новая кнопка</li>')
        $("#keyboard ul").last().children().css('width', '100%')
    }

    $("#keyboard ul").sortable(sortableSettings)
    $("#keyboard ul li").last().click(btnClick)
    $("#keyboard ul li").last().dblclick(btnDblClick)
}

// Создание модальных окон
let createModals = function () {
    $("#button-settings").dialog({
        width: 650,
        autoOpen: false,
        resizable:false,
        modal:true,
        buttons:{
            "Сохранить": saveButtonInfo,
            "Удалить": deleteButton,
            "Отменить": cancelEditingBtnInfo
        },
        close: clearModal
    });

    $("#button-info").dialog({
        width: 650,
        autoOpen: false,
        resizable:false,
        modal:true,
        buttons:{
            "Ok": function(){
                $(this).dialog( "close" );
            }
        }
    });

    $("#button-go-next-step").dialog({
        width: 650,
        autoOpen: false,
        resizable:false,
        modal:true,
        buttons:{
            "Отменить": function(){
                $(this).dialog( "close" );
            }
        }
    });
}

// Очистка полей модального окна
let clearModal = function () {
    $('#btnCaption').val('')
    $('#btnReply').html('')
    $('#btnNextScreen').val('')
    $('#btnNextScreen').html('')
}

// Обработка кнопки "Сохранить" модального окна
let saveButtonInfo = function(){
    $(editedButton).html($("#btnCaption").val())
    $(editedButton).attr('data-reply', $("#btnReply").val())
    $(editedButton).attr('data-next-screen', $("#btnNextScreen").val())

    clearModal()
    $(this).dialog( "close" )
}

// Обработка кнопки "Удалить" модального окна
let deleteButton = function () {
    var btnParent = $(editedButton).parent()
    $(editedButton).remove()
    editBtnSize(btnParent)
    clearModal()
    $(this).dialog("close")
}

// Обработка кнопки "Отменить" модального окна
let cancelEditingBtnInfo = function(){
    clearModal()
    $(this).dialog( "close" )
}

// Отправляем информацию о новом шаге
let sendScreenInfo = function () {
    let screenInfo = new Object()

    screenInfo.screen_id = $('.screenLabel.selected').attr('data-screen-number')
    screenInfo.screen_name = $('#screenName').val()
    screenInfo.screen_message = $('#screenMessage').val()

    screenInfo.keyboard = new Array()
    $('#keyboard ul').each(function (ind, row) {
        $(row).children().each(function (ind2, btn) {
            let btnInfo = new Object()

            btnInfo.caption = $(btn).html()
            btnInfo.button_row = ind + 1
            btnInfo.reply = $(btn).attr('data-reply')
            if (btnInfo.reply == undefined)
                btnInfo.reply = ''
            btnInfo.next_screen = $(btn).attr('data-next-screen')


            screenInfo.keyboard.push(btnInfo)
        })
    })

    console.log(screenInfo)
    $.ajax({
        url: "php/addingNewScreen.php",
        type: "POST",
        data: screenInfo,
        success: function (res) {
            console.log('ok')
        }
    })
}

// Сохраняем результат редактирования
let saveEdit = function(){
    editOFF()
    sendScreenInfo()
    $("#main h2").html($('#screenName').val());
    $(".screenLabel.selected").html($('#screenName').val())
}

// Обработчик двойного клика по кнопка клавиатуры
// В режиме редактирования открывает окно редактирования свойств кнопки
// В обычном режиме выводи тект ответа для пользователя, если последний нажмет на эту кнопку
let btnDblClick = function () {
    if (onEdit) {
        editedButton = this

        initModalSettings(this)
        $("#button-settings").dialog("open")
    } else {
        initModalInfo(this)
        $("#button-info").dialog("open")
    }
}

// Инициализация модального окна с найстройками кнопки
let initModalSettings = function (btn) {
    $('#btnCaption').val($(btn).html())
    $('#btnReply').val($(btn).attr('data-reply'))

    $('.screenLabel').each(function (ind, value) {
        $('#btnNextScreen').append("<option value='"+$(value).attr('data-screen-number')+"'>"+$(value).html()+"</option>")
    })

    let nextScreen = $(btn).attr('data-next-screen')

    $('#btnNextScreen').append("<option value='"+nextScreen+"'>Новый экран ("+nextScreen+")</option>")
    $('#btnNextScreen').val(nextScreen)
}

// Удаление экрана
let deleteScreen = function () {
    let screenID = $('.screenLabel.selected').attr('data-screen-number')
    editOFF()
    $('.screenLabel.selected').remove()

    $('.screenLabel').first().click()

    $.ajax({
        url: 'php/deleteScreen.php',
        type: 'GET',
        data: {'screen_id': screenID},
        success: function () {
            console.log('ok')
        }
    })
}
//----------------------------------------------------------------------------------------------------------------------
// В работе
//----------------------------------------------------------------------------------------------------------------------


















