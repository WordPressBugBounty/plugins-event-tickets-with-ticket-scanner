function sasoEventtickets_js_seatingplan(PARAS) {
    let CONTROL_PANEL = null;

    function init(cbf) {
        addScriptTag(myAjax._plugin_home_url+"/3rd/raphael/raphael_2.3.0.min.js", 'raphael', ()=>{
            console.log('Seating plan JS initialized');
            console.log(PARAS);
            cbf && cbf();
		}, {'crossorigin':"anonymous", "charset":"utf8"});
    }

    function displaySeatingPlan() {
        let div = PARAS.div;
        // draw 2 divs next to each other
        div.html("");
        div.css("display", "flex");
        div.css("flex-direction", "row");
        div.css("align-items", "flex-start");
        div.css("justify-content", "space-between");
        div.css("width", "100%");
        div.css("height", "100%");

        let div2 = $('<div style="width:80%;background:white;padding:15px;border-radius:15px;">').appendTo(div);
        CONTROL_PANEL = $('<div style="width:20%;background:yellow;padding:15px;border-radius:15px;">').appendTo(div);

        let is_plan_loaded = false;

        // https://dmitrybaranovskiy.github.io/raphael/reference.html#Paper.setSize

        div2.html("");
        let div_drawing_area_id = myAjax.divPrefix + '_div_drawing_area';
        let div_drawing_area = $('<div style="border:2px solid black;width:80%;height:400px;background-color:white;" id="'+div_drawing_area_id+'"></div>')

        let btn_grp = $('<div style="margin-bottom:15px;">').appendTo(div2);
        let btn_grp_2 = $('<div style="margin-bottom:15px;visibility:hidden;">').appendTo(div2);

        let input_pre = $('<input type="checkbox">');
        let input_x = $('<input type="number" placeholder="x-offset">');
        let input_y = $('<input type="number" placeholder="y-offset">');
        if (PARAS.calibrate) {
            input_pre.appendTo(div2);
            input_x.appendTo(div2);
            input_y.appendTo(div2);
        }

        div_drawing_area.appendTo(div2);
        let info = $('<div>').appendTo(div2); // for messages like "Seat added" or "saved"

        // not allowed functions, because it will be serialized and stored in the database
        function Seat(svgObject, typeName) {
            this.svgObject = svgObject;
            this.typeName = typeName;
            this.attr = {};
        }

        let seats = [];
        let paper;

        let _info_timer = null;
        function _setInfo(msg, dont_clear) {
            if (_info_timer) {
                clearTimeout(_info_timer);
            }
            info.html(msg);
            if (!dont_clear) {
                _info_timer = setTimeout(()=>{
                    info.html("");
                }, 2000);
            }
        }

        $('<button>').text("Add new Seating plan").on("click", e=>{
            if (is_plan_loaded) {
                if (confirm("Overwrite plan?")) {
                    __renderArea();
                }
            } else {
                __renderArea();
            }
            function __renderArea() {
                is_plan_loaded = true;
                btn_grp_2.css("visibility", "visible");
                div_drawing_area.html("");
                paper = Raphael(div_drawing_area[0], "100%", 400);
                paper.text(50,10, "Seating plan");
                //console.log(div_drawing_area.html());
            }
        }).addClass("button-primary").appendTo(btn_grp);

        $('<button>').text("Add new Seat Box").on("click", e=>{
            let circle;
            let drag_start_x = 50;
            let drag_start_y = 40;
            //circle = paper.circle(50, 40, 10);
            circle = paper.rect(drag_start_x, drag_start_y, 40, 40);
            let seat = new Seat(circle, "box");
            seat.attr['name'] = "Seat "+(seats.length+1);
            seats.push(seat);
            circle.attr("fill", "#f00000");
            circle.attr("stroke", "#fff");
            circle.attr("name", "Seat "+seats.length);

            circle.drag((dx,dy,x,y,elem)=>{ // onmove
                circle.attr("x", dx+drag_start_x);
                circle.attr("y", dy+drag_start_y);
            }, (x,y,elem)=>{ // onstart
                circle.attr("opacity", 0.7);
            }, (x,y,elem)=>{ // onend
                drag_start_x = circle.attr("x");
                drag_start_y = circle.attr("y");
                circle.attr("opacity", 1);
            });
            _setInfo("Seat added");
        }).addClass("button-primary").appendTo(btn_grp_2);

        $('<button>').text("Add new Seat Circle").on("click", e=>{
            let circle;
            let drag_start_x = 50;
            let drag_start_y = 40;
            circle = paper.circle(drag_start_x, drag_start_y, 20);
            let seat = new Seat(circle, "box");
            seat.attr['name'] = "Seat "+(seats.length+1);
            seats.push(seat);
            circle.attr("fill", "#f00000");
            circle.attr("stroke", "#fff");
            circle.attr("name", "Seat "+seats.length);

            // allow the circle to be dragged
            circle.drag((dx,dy,x,y,elem)=>{ // onmove
                circle.attr("cx", dx+drag_start_x);
                circle.attr("cy", dy+drag_start_y);
            }, (x,y,elem)=>{ // onstart
                circle.attr("opacity", 0.7);
            }, (x,y,elem)=>{ // onend
                drag_start_x = circle.attr("cx");
                drag_start_y = circle.attr("cy");
                circle.attr("opacity", 1);
            });
            _setInfo("Seat added");

            circle.click(()=>{
                _setInfo("Seat selected");
                CONTROL_PANEL.html("");

                let seat = seats.find(s=>s.svgObject==circle);

                let seat_id = seats.indexOf(seat);
                let seat_name = $('<input type="text" placeholder="Give seat name/number">').appendTo(CONTROL_PANEL);
                let seat_color = $('<input type="color">').appendTo(CONTROL_PANEL);
                seat_color.val(seat.svgObject.attr("fill"));
                let seat_save = $('<button>').text("Save").appendTo(CONTROL_PANEL);
                seat_save.on("click", e=>{
                    console.log("save");
                    seat.attr["name"] = seat_name.val();
                    let c = seat_color.val();
                    seat.svgObject.attr("fill", c);
                    seat.svgObject.attr("stroke", c == '#ffffff' ? '#000' : '#fff');
                    _setInfo("Seat saved");
                });
                let seat_delete = $('<button>').text("Delete").appendTo(CONTROL_PANEL);
                seat_delete.on("click", e=>{
                    if (confirm("Delete seat?")) {
                        seats.splice(seat_id, 1);
                        seat.svgObject.remove();
                        _setInfo("Seat deleted");
                    }
                });
                seat_name.val(seat.attr['name'] ? seat.attr['name'] : '');
                seat_color.val(circle.attr("fill"));
            });

            /*
            circle.dblclick(()=>{
                console.log("double clicked");
                console.log(seats);
            });
            */

            circle.node.click();

        }).addClass("button-primary").appendTo(btn_grp_2);
    }

    function start() {
        init(displaySeatingPlan);
    }

    start();
}