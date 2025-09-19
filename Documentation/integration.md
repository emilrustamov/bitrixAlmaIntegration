


Alma External API Documentation
23 pages

Авторизация
Для авторизации ко всем запросам добавляется заголовок Api-Key:{{ you_key }}

## 🌐 Alma Web Interface

### Иерархия объектов в Alma
Alma использует следующую иерархию объектов (от верхнего уровня к нижнему):

1. **Проекты** - https://colife.alma-app.com/projects/{id}
   - Пример: [Проект Dubai](https://colife.alma-app.com/projects/207)

2. **Здания** - https://colife.alma-app.com/buildings/{id}
   - Пример: [Здание 357](https://colife.alma-app.com/buildings/357)

3. **Апартаменты/Юниты** - https://colife.alma-app.com/units/{id}
   - Пример: [Апартамент 2613](https://colife.alma-app.com/units/2613)

4. **Комнаты** - https://colife.alma-app.com/rooms/{id}
   - Пример: [Комната 5535](https://colife.alma-app.com/rooms/5535)

### Структура ссылок
- **Проекты**: `/projects/{project_id}`
- **Здания**: `/buildings/{building_id}` 
- **Апартаменты**: `/units/{unit_id}`
- **Комнаты**: `/rooms/{room_id}`

> **Примечание**: Иерархия идет сверху вниз: Проект → Здание → Апартамент → Комната

### ⚠️ Ограничения API

**Недоступные функции:**
- 🔍 **Поиск по API** - поиск объектов по названию, адресу или другим критериям через API недоступен
- 🗑️ **Удаление объектов** - удаление апартаментов, юнитов, зданий или контрактов через API недоступно
- 📋 **Массовые операции** - массовое создание, обновление или архивирование объектов не поддерживается

**Доступные альтернативы:**
- Используйте веб-интерфейс Alma для поиска и удаления объектов
- Для поиска используйте GET запросы к спискам объектов с фильтрацией на стороне клиента
- Для "удаления" используйте архивирование через соответствующие PATCH endpoints

## 📋 Маппинг полей Bitrix24 → Alma

### Критически важные поля для контрактов
- `UF_CRM_20_1693919019` - **Apartments** (ID апартамента в контрактах)
- `UF_CRM_20_CONTRACT_START_DATE` - дата начала аренды
- `UF_CRM_20_CONTRACT_END_DATE` - дата окончания аренды  
- `OPPORTUNITY_WITH_CURRENCY` - сумма контракта

### Поля для апартаментов
- `UF_CRM_6_1682232363193` - Building (название здания)
- `UF_CRM_6_1682232396330` - Apartment Number
- `UF_CRM_6_1718821717` - Address
- `UF_CRM_6_1682232312628` - Floor
- `UF_CRM_6_1682232863625` - Type (тип апартамента)
- `UF_CRM_6_1682233481671` - Dubai Metro (Subway Station)
- `UF_CRM_6_1682235809295` - Wi-Fi Name (Internet login)
- `UF_CRM_6_1686728251990` - Wi-Fi Password (Internet password)
- `UF_CRM_6_1683299159437` - Parking number
- `UF_CRM_6_1715777670` - Pass from Electrical Lock
- `UF_CRM_6_1720794204` - Keybox Code

### Поля для юнитов (комнат)
- `UF_CRM_8_1698838056004` - Number of Unit (номер комнаты)
- `UF_CRM_8_1682957076924` - Housing Type
- `UF_CRM_8_1699620518232` - Target Rental Amount

### Поля для клиентов
- `NAME` - First Name
- `LAST_NAME` - Last Name
- `UF_CRM_1727788747` - Email
- `PHONE` (тип work) - Phone
- `UF_CRM_20_1696523391` - Passport/ID Card Number
- `UF_CRM_20_1696615939` - Passport/ID Card File

### Статусы (не пробрасываются автоматически)
- **Apartments**: 5974 Available, 5978 Rented, 5980 Blocked, 5982 Ex-Apartments
- **Units**: 5970 Available, 5972 Rented, 5968 Blocked, 5974 Ex-Units

Apartments
POST: external_api/realty/units/ 
{
   "external_id": "21_01_8",
   "additional_external_id": "21_01_7_1",
   "is_used_additional_external_id": true,
   "name": "21_01_8",
   "header": "21_01_8",
   "building": 1075,
   "property_type": "detached_villa",
   "number": "123",
   "internal_area": 1111.0,
   "photos": [
       {
           "external_file_id": 0
       }
   ],
   "goal_rent_cost": 12.22,
   "address": "1231",
   "number_of_bedrooms": 1,
   "number_of_baths": 1,
   "is_roof_garden": false,
   "parking": "not_applicable",
   "is_swimming_pool": false,
   "total_buildable_area": 1111.0,
   "floor": null,
   "internet_login": "",
   "internet_password": "",
   "subway_station": "",
   "parking_number": "",
   "keybox_code": null,
   "electronic_lock_password": null
}
Пояснения:
external_id – id апартамента из битрикса {{264204742__id}}
additional_external_id – если апартамент не шеренговый, то это id юнита из битрикса 
is_used_additional_external_id – этот параметр отвечает за то, будет ли возвращена эта запись ручкой /external_api/realty/rental_object/{{external_id}}/  по additional_external_id. Это важный параметр из-за него часто интеграция работает не верно. {{264204742__ufCrm6_1736951470242}}


Bitrix
Alma
4600
false
4598
true



name – просто имя, должно быть уникально внутри здания {{264204742__title}}
header – {{264204742__title}}
building – id здания в Alma
property_type – тип апартаментамета {{264204743__apartment_type}}


Bitrix
Alma
54
apartment
56
apartment
58
apartment
60
apartment
62
apartment
64
apartment
52
studio



number – {{264204742__ufCrm6_1682232396330}} Если пустая строка, то передайте 0.
internal_area – {{264204742__ufCrm6_1682232424142}} Если пустая строка, то передайте 0.
photos – Если фото нет то передаете пустой список, если есть то предварительно загрузите изображение через /external_api/external-image/ и сюда передаем список объектов        [{"external_file_id": 9146}, {"external_file_id": 9144}].
goal_rent_cost – {{264204742__ufCrm6_1682232447205}}
address – {{264204742__ufCrm6_1718821717}}
number_of_bedrooms – {{264204742__ufCrm6_1682232863625}}


Bitrix
Alma
54
1
56
2
58
3
60
4
62
5
64
6
52
1



number_of_baths – {{264204742__ufCrm6_1682232465964}} Если пустая строка, то передайте 0.
is_roof_garden – всегда false
parking – всегда not_applicable
is_swimming_pool – {{264204742__ufCrm6_1697622591377}}
total_buildable_area – {{{{264204742__ufCrm6_1682232424142}}}} Если пустая строка, то передайте 0.
floor – {{264204742__ufCrm6_1682232312628}}
internet_login – {{264204742__ufCrm6_1682235809295}}
internet_password – {{264204742__ufCrm6_1686728251990}}
subway_station – {{264204742__ufCrm6_1682233481671}}


Bitrix
Alma
66
Rashidiya
68
Emirates
70
Airport Terminal 3
72
Airport Terminal 1
74
GGICO
76
Deira City Centre
78
Al Rigga
80
Union
82
Bur Juman
84
ADCB
86
Al Jaffiliya
88
World Trade Centre
90
Emirates Towers
92
Financial Centre
94
Burj Khalifa / Dubai Mall
96
Business Bay
98
Noor Bank
100
First Gulf Bank (FGB)
102
Mall of the Emirates
104
Sharaf DG
106
Dubai Internet City
108
Nakheel
110
Damac Properties
112
DMCC
114
Nakheel Harbor and Tower
116
Ibn Battuta
118
Energy
120
Danube
122
UAE Exchange
124
Etisalat
126
Al Qusais
128
Dubai Airport Free Zone
130
Al Nahda
132
Stadium
134
Al Quiadah
136
Abu Hail
138
Abu Baker Al Siddique
140
Salah Al Din
142
Baniyas Square
144
Palm Deira
146
Al Ras
148
Al Ghubaiba
150
Al Fahidi
152
Oud Metha
154
Dubai Healthcare City
156
Al Jadaf
158
Creek
182
Sobha realty
184
Al Furjan
254
Centrepoint
2762
Not selected
2762
null



parking_number – {{264204742__ufCrm6_1683299159437}}
keybox_code – {{264204742__ufCrm6_1720794204}}
electronic_lock_password – {{264204742__ufCrm6_1715777670}}


GET: external_api/realty/units/
[
   {
       "id": 9209,
       "name": "21_01_11",
       "header": "",
       "external_id": "21_01_11"
   },
   {
       "id": 9208,
       "name": "21_01_12",
       "header": "",
       "external_id": "21_01_12"
   },
]
GET: external_api/realty/units/{{alma_id}}/ или external_api/realty/units/external_id/{{bitrix_id}}/ 
{
   "id": 9199,
   "external_id": "21_01_8",
   "additional_external_id": "21_01_7_1",
   "is_used_additional_external_id": true,
   "name": "21_01_8",
   "header": "",
   "building": {
       "id": 1075,
       "name": "Building Import 1"
   },
   "property_type": {
       "name": "property_type.detached_villa",
       "value": "detached_villa"
   },
   "number": "123",
   "internal_area": 1111.0,
   "status": "available",
   "project": {
       "id": 518,
       "name": "Project Import 1"
   },
   "is_archived": false,
   "photos": [],
   "goal_rent_cost": 12.22,
   "address": "1231",
   "address_number": "121",
   "number_of_bedrooms": 1,
   "number_of_baths": 1,
   "is_roof_garden": false,
   "parking": {
       "name": "parking_type.uncovered",
       "value": "uncovered"
   },
   "plot": null,
   "is_swimming_pool": false,
   "covered_verandas": null,
   "uncovered_verandas": null,
   "lower_level": null,
   "storage": null,
   "total_buildable_area": 1111.0,
   "floor": null,
   "internet_login": "",
   "internet_password": "",
   "subway_station": "",
   "parking_number": "",
   "keybox_code": null,
   "electronic_lock_password": null
}
PATCH: external_api/realty/units/{{alma_id}}/ или external_api/realty/units/external_id/{{bitrix_id}}/ 
Если обновляется поле name, то обязательно передать building. 
Если нужно обновить набор изображений, то передаем список вида 
   "photos": [
       {"id": 4506}, – указываем уже прикрепленные файлы, файлы которые не были указаны будут удалены 
       {
           "external_file_id": 9147 – новые изображения
       }
   ]
Остальные поля обновляются без особенностей.
Значение поля status – самостоятельно изменить нельзя.
Значение is_archived – меняется через другую ручку.

PATCH: external_api/realty/units/{{alma_id}}/archive/ или external_api/realty/units/external_id/{{bitrix_id}}/archive/ 
{
   "is_archived": true | false
}
Архивирует апартаменты, все привязанные к нему юниты и все активные и запланированные контракты. Разархивировать контракты потом будет нельзя. Клиент оставшийся без активного контракта потеряет доступ к приложению.

При разархивации, разархивируется апартаменты, все привязанные к нему юниты и проект и здание.

 PATCH: external_api/realty/units/{{alma_id}}/block/ или external_api/realty/units/external_id/{{bitrix_id}}/block/ 

{
   "is_blocked": true
}


Блокирует апартаменты, апартаменты блокируется бессрочно с текущей даты, также будут заблокированы все привязанные к нему юниты без создания фактической блокировки на них. Для блокировки апартаментыу него или у его юнитов не должно быть не одного активного или запланированного контракта на аренду или блокировку.. Из особенностей, ручку будет при успехи возвращать true, а status апартаменты в ответе может не измениться, это нормально, статусы меняются по времени, при создание блокировки создать контракт уже будет нельзя, даже без фактического изменения значения поля status.

При разблокирование апартамента, удаляются все активные бессрочные блокировки и блокировки чье начала запланировано на дату вызова, блокировки созданные на привязанные юниты удалены не будут.

Units
POST: external_api/realty/rooms/ – Если юнит, относится к апартаментаменту без шеренга, то нельзя о нем создавать запись о нем в Alma, впишите его external_id в additional_external_id его апартамента и не забудьте поставить is_used_additional_external_id=true, если по бизнесу нужно будет обновить какие-то поля у этой несуществующей записе, то через /external_api/realty/rental_object/{{external_id}}/  получите id нужно записи и работайте с ней. 

{
   "external_id": "12_81_12",
   "goal_rent_cost": 12.2,
   "name": "New name",
   "header": "21_01_8",
   "internal_area": 0,
   "photos": [
       {
           "external_file_id": 0
       }
   ],
   "parent_unit": 9192,
   "property_type": "bunk"
}
Пояснения:
external_id – id апартамента из битрикса {{264203980__id}}
name – имя комнаты, заполняется по правилам {{264203980__ufCrm8_1698838056004}}


Bitrix
Alma
1294
1
1296
2
1298
3
1300
4
1302
5
5902
Bed1



header – {{264203980__title}}
number – {{264203980__ufCrm8_1686662655574}}
goal_rent_cost – {{264203980__ufCrm8_1699620518232}}
internal_area – {{264203980__ufCrm8_1686662606534}} Если пустая строка, то передайте 0.
parent_unit – внутренний id апартамента в альме
photos – Если фото нет то передаете пустой список, если есть то предварительно загрузите изображение через /external_api/external-image/ и сюда передаем список объектов        [{"external_file_id": 9146}, {"external_file_id": 9144}].
property_type – {{264203980__ufCrm8_1682957076924}}


Bitrix
Alma
244
bunk
242
room



GET: external_api/realty/rooms/
[
   {
       "id": 9209,
       "name": "21_01_11",
       "header": "",
       "external_id": "21_01_11"
   },
   {
       "id": 9208,
       "name": "21_01_12",
       "header": "",
       "external_id": "21_01_12"
   },
]
GET: external_api/realty/rooms/{{alma_id}}/ или external_api/realty/rooms/external_id/{{bitrix_id}}/ 
{
   "id": 9211,
   "external_id": "12_12_12",
   "name": "12_12_12",
   "header": "21_01_11",
   "goal_rent_cost": 12.2,
   "parent_unit": {
       "id": 9209,
       "name": "21_01_11",
       "header": "21_01_11",
       "external_id": "21_01_11"
   },
   "building": {
       "id": 1075,
       "name": "Building Import 1"
   },
   "property_type": {
       "name": "property_type.bunk",
       "value": "bunk"
   },
   "number": "",
   "internal_area": 0.0,
   "status": "available",
   "project": {
       "id": 518,
       "name": "Project Import 1"
   },
   "is_archived": false,
   "photos": [],
   "keybox_code": null,
   "electronic_lock_password": null
}
PATCH: external_api/realty/rooms/{{alma_id}}/ или external_api/realty/rooms/external_id/{{bitrix_id}}/ 
Если обновляется поле name, то обязательно передать parent_unit. 
Если нужно обновить набор изображений, то передаем список вида 
   "photos": [
       {"id": 4506}, – указываем уже прикрепленные файлы, файлы которые не были указаны будут удалены 
       {
           "external_file_id": 9147 – новые изображения
       }
   ]
Остальные поля обновляются без особенностей.
Значение поля status – самостоятельно изменить нельзя.
Значение is_archived – меняется через другую ручку.
PATCH: external_api/realty/rooms/{{alma_id}}/archive/ или external_api/realty/rooms/external_id/{{bitrix_id}}/archive/ 
{
   "is_archived": true | false
}
Архивирует юнит все активные и запланированные контракты на нем. Разархивировать контракты потом будет нельзя. Клиент оставшийся без активного контракта потеряет доступ к приложению.

При разархивации, разархивируется юнит, апартаментамент, проект и здание.

 PATCH: external_api/realty/rooms/{{alma_id}}/block/ или external_api/realty/rooms/external_id/{{bitrix_id}}/block/ 

{
   "is_blocked": true
}


Блокирует юнит, юнит блокируется бессрочно с текущей даты. Для блокировки юнита не должно быть не одного активного или запланированного контракта на аренду или блокировки. Из особенностей, ручку при успехи будет возвращать true, а status юнита в ответе может не измениться, это нормально, статусы меняются по времени, при создание блокировки создать контракт уже будет нельзя, даже без фактического изменения значения поля status.

При разблокирование юнит, удаляются все активные бессрочные блокировки и блокировки чье начала запланировано на дату вызова.

Buildings
POST: external_api/realty/buildings/ 
{
   "name": "02_02_03",
   "project": 305
}
Пояснения:
name – {{264204742__ufCrm6_1682232363193}} внутри проекта, имя должно быть уникальным 
project – на проде всегда 207, на деве всегда 240
GET: external_api/realty/buildings/
[
   {
       "id": 1088,
       "name": "02_02_03"
   },
   {
       "id": 1087,
       "name": "тест"
   },
]
GET: external_api/realty/buildings/{{alma_id}}/ или external_api/realty/buildings/external_id/{{bitrix_id}}/ 
{
   "id": 1088,
   "name": "02_02_03",
   "project": {
       "id": 305,
       "name": "Test
   },
   "number_of_units": 0,
   "address": "",
   "is_archived": false,
   "number": "",
   "external_id": ""
}
PATCH: external_api/realty/buildings/{{alma_id}}/ или external_api/realty/buildings/external_id/{{bitrix_id}}/ 
Если обновляется поле name, то обязательно передать project.
Остальные поля обновляются без особенностей.
Значение is_archived – меняется через другую ручку.


Rental object
GET: external_api/realty/rental_object/{{bitrix_id}}/ ручка возвращает наиболее подходящую запись по внешнему id, если ни одной записи не было найдено, то вернет 404. С учетом того, что не все записи из Битрикса должны существовать в Альме, пользоваться необходимо этой ручку для определение внутреннего id апартамента или юнита.
Response:
{
   "id": 5533, // внутренний id, для создание контрактов используем его
   "external_id": "1022", // id в Битрикс
   "additional_external_id": "", // id юнита, для не шеренгового апартамента
   "is_used_additional_external_id": false, // флаг использования
   "parent_unit": 2613 // если не null то значит вернулся юнит, а не апартамент
}


Clients
POST: external_api/users/clients/ – клиент по умолчанию создается архивным, из-за внутренней логики они считаются не до конца созданными, таких клиентов не видно в веб версии.  После заведение контракта его создание завершится и статус изменится. 
{
 "external_id": "02_02",
 "first_name": "William",
 "last_name": "Lee",
 "email": "test@gmail.ru",
 "phone": "+79000000000",
 "country": 4,
 "passport_scan": 9149, // – поле не обязательно
 "id_scan": 9148, // – поле не обязательно
 "birthday": "2019-08-24T00:00:00"
}
external_id – id контакта {{266283216__contact[]ID}}
first_name – {{266283216__contact[]NAME}}
last_name – {{266283216__contact[]LAST_NAME}}
email – {{266283216__contact[]UF_CRM_1727788747}}
phone – {{266283216__contact[]Phone_work_0}} или {{266283216__Phone_work_0}}
country – всегда 4
passport_scan – {{266283214__ufCrm10_1694000435068__urlMachine}} Нужно подставить id ранее загруженного файла по аналогии с апартаментамми
id_scan – не знаю откуда берется, поле не обязательно. Нужно подставить id ранее загруженного файла по аналогии с апартаментамми
birthday – {{266283216__contact[]BIRTHDATE}}

PATCH: external_api/users/clients/{{alma_id}}/ или external_api/users/clients/external_id/{{bitrix_id}}/ 
Ранее добавленный изображение пока нет способа удалить. Других особенностей для этого метода нет.

GET: external_api/users/clients/ - получение списка всех клиентов
[
   {
       "id": 1,
       "external_id": "8",
       "first_name": "Client",
       "last_name": "Clientov",
       "status": "active",
       "email": "test_client@poop.paap"
   },
   {
       "id": 255,
       "external_id": "",
       "first_name": "Mikhail",
       "last_name": "Mikhail",
       "status": "active",
       "email": "ranep79489@nasskar.com"
   },
]
GET: external_api/users/clients/{{alma_id}}/ или external_api/users/clients/external_id/{{bitrix_id}}/ 

{
   "id": 851,
   "external_id": "02_02",
   "first_name": "William",
   "last_name": "Lee",
   "status": "unconfirmed",
   "email": "test3@gmail.ru",
   "phone": "+79000000000",
   "passport": "",
   "country": {
       "name": "country.cyprus",
       "value": 4
   },
   "id_number": "",
   "passport_scan": "/private-media/127/clients_files/client_851/passport_scan/test_logo_ZLCkSIq.jpg",
   "id_scan": "/private-media/127/clients_files/client_851/id_scan/test_logo.jpg",
   "birthday": "2019-08-24T00:00:00"
}


Contracts:

POST: external_api/realty/contracts/owner_contracts/ – создает контракт на владение. Контракт на владение создается только на апартаментамент.
{
   "external_id": 4330,
   "unit_id": 4330,
   "client_id": 256,
   "name": "string2",
   "start_date": "2024-10-21T00:10:10Z",
   "end_date": "2024-10-29T00:10:10Z",
   "contract_with_client_scan": null,
   "property_title_deed_scan": null,
   "property_management_letter_scan": null,
   "dtcm_permit_scan": null,
   "pml_start_date": "2024-07-22T00:10:10Z",
   "pml_end_date": "2024-08-22T00:10:10Z",
   "work_model": "TM 10%"
}
external_id – {{266283214__id}}
unit_id – внутрений id ранее добавленного объекта аренды
client_id – внутрений id ранее добавленного клиента
name – {{266283214__title}}
start_date – {{266283214__ufCrm10_1693823247516}}
end_date – {{266283214__ufCrm10_1693823282826}}
contract_with_client_scan – внутрений id ранее добавленного изображения {{266283214__ufCrm10_1709042143__urlMachine}}
property_title_deed_scan – внутрений id ранее добавленного изображения {{266283214__ufCrm10_1694000636731__urlMachine}}
property_management_letter_scan – внутрений id ранее добавленного изображения {{266283214__ufCrm10_1694000391518__urlMachine}}
dtcm_permit_scan – внутрений id ранее добавленного изображения {{266283214__ufCrm10_1694000558852__urlMachine}}
pml_start_date – {{266283214__ufCrm10_1708956056}}
pml_end_date – {{266283214__ufCrm10_1708955996}}
work_model – {{266283214__ufCrm10_1708955821}}


Bitrix
Alma
1696
Rent to rent
1698
TM 10%
1700
TM 15%
1702
TM 17%
1704
TM 20%
1706
Long term
1730
TM 18%





GET: external_api/realty/contracts/owner_contracts/
[
   {
       "id": 163,
       "number": "",
       "external_id": ""
   },
   {
       "id": 61,
       "number": "",
       "external_id": ""
   },
]
GET: external_api/realty/contracts/owner_contracts/{{alma_id}}/ или external_api/realty/rooms/contracts/owner_contracts/{{bitrix_id}}/ 
{
   "id": 542,
   "name": "string2",
   "number": "",
   "start_date": "2024-10-21T00:00:00",
   "end_date": "2024-10-29T00:00:00",
   "external_id": "4330",
   "unit_usage": {
       "usage_id": 1941,
       "client_id": 256,
       "unit_id": 4330,
       "client_type": "owner",
       "is_archived": false
   },
   "contract_with_client_scan": null,
   "property_title_deed_scan": null,
   "property_management_letter_scan": null,
   "dtcm_permit_scan": null,
   "pml_start_date": "2024-07-22T00:00:00",
   "pml_end_date": "2024-08-22T00:00:00",
   "work_model": "TM 10%"
}
PATCH: external_api/realty/contracts/owner_contracts/{{alma_id}}/ или external_api/realty/contracts/owner_contracts/external_id/{{bitrix_id}}/ 
Без особенностей. 

POST: external_api/realty/contracts/tenant_contracts/ – создает контракт на аренду. Если у не шерингового апартамента в альме почему то будет прикреплен юнит, то создать на него контракт не получиться. На заблокированные юниты контаркт также не получиться создать, если даты из start_date и end_date будут пересекать с датами других контрактов на выбранном юните, то будет исключение.

{
   "external_id": "02_o2",
   "unit_id": 9212,
   "client_id": 250,
   "name": "test",
   "start_date": "2025-08-21T00:00:00",
   "end_date": "2025-08-25T00:00:00",
   "price": "0.00",
   "contract_scan": null,
   "co_tenant": "text",
   "history": "text",
   "type_contract": "text",


   "co_tenant_id_scan": null,
   "co_tenant_id": "text"
}
external_id – {{266283064__id}}
unit_id – внутрений id ранее добавленного объекта аренды
client_id – внутрений id ранее добавленного клиента
name – {{266283064__title}}
start_date – {{266283064__ufCrm20ContractStartDate}}
end_date – {{266283064__ufCrm20ContractEndDate}}
price – {{266283064__opportunity}}
contract_scan – внутрений id ранее добавленного изображения 
{{266283064__ufCrm20Contract[]urlMachine}}
co_tenant_id_scan – внутрений id ранее добавленного изображения, не знаю откуда взять из битрикса, поле не обязательное
history – {{266283064__ufCrm20ContractHistory}}
co_tenant_id – текстовое поле, не знаю откуда взять из битрикса, поле не обязательное
type_contract – {{266283064__ufCrm20_1693561495}}


Bitrix
Alma
882
Airbnb
884
Short term from 1 to 3 months
886
Long-term 3+ months
1304
Booking
1306
Short contract up to 1 month



GET: external_api/realty/contracts/tenant_contracts/
[
   {
       "id": 445,
       "number": "",
       "external_id": ""
   },
   {
       "id": 303,
       "number": "Номер какой-то 2",
       "external_id": "324"
   },
]
GET: external_api/realty/contracts/tenant_contracts/{{alma_id}}/ или external_api/realty/rooms/contracts/tenant_contracts/{{bitrix_id}}/ 
{
   "id": 969,
   "name": "test",
   "number": "",
   "start_date": "2025-08-21T00:00:00",
   "end_date": "2025-08-25T00:00:00",
   "external_id": "02_o2",
   "unit_usage": {
       "usage_id": 1942,
       "client_id": 250,
       "unit_id": 9212,
       "client_type": "tenant",
       "is_archived": false
   },
   "price": "0.00",
   "contract_scan": null,
   "co_tenant": "text",
   "co_tenant_id_scan": null,
   "history": "text",
   "type_contract": "text",
   "co_tenant_id": "text"
}
PATCH: external_api/realty/contracts/tenant_contracts/{{alma_id}}/ или external_api/realty/contracts/tenant_contracts/external_id/{{bitrix_id}}/ 

Без особенностей
