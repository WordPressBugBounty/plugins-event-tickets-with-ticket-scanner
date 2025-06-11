function sasoEventtickets_js_seatingplan(PARAS) {
    let CONTROL_PANEL = null;
    let CONTROL_PANEL2 = null;

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
        CONTROL_PANEL2 = $('<div style="width:20%;background:#efefef;padding:15px;border-radius:15px;">').appendTo(div);

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

        function listAllObjects() {
            CONTROL_PANEL2.html("");
            let ul = $('<ul>').appendTo(CONTROL_PANEL2);
            seats.forEach((seat, index) => {
                let li = $('<li>').text(seat.typeName + " " + (index+1) + ": " + seat.attr['name']).appendTo(ul);
                li.on("click", e=>{
                    paper.getById(seat.svgObject.id).node.click();
                });
            });
            if (seats.length == 0) {
                $('<li>').text("No seats added yet").appendTo(ul);
            }
            if (paper) {
                let text_objects = getByType("text");
                if (text_objects.length > 0) {
                    $('<li>').text("Text objects:").appendTo(ul);
                    text_objects.forEach((text, index) => {
                        let li_text = $('<li>').text("Text " + (index+1) + ": " + text.attr('text')).appendTo(ul);
                        li_text.on("click", e=>{
                            text.node.click();
                        });
                    });
                }
            }
            if (paper && getByType("image").length > 0) {
                $('<li>').text("Image objects:").appendTo(ul);
                getByType("image").forEach((img, index) => {
                    let li_img = $('<li>').text("Image " + (index+1) + ": " + img.attr('src')).appendTo(ul);
                    li_img.on("click", e=>{
                        img.node.click();
                    });
                });
            }
            if (paper && getByType("path").length > 0) {
                $('<li>').text("Path objects:").appendTo(ul);
                getByType("path").forEach((path, index) => {
                    let li_path = $('<li>').text("Path " + (index+1) + ": " + path.attr('d')).appendTo(ul);
                    li_path.on("click", e=>{
                        path.node.click();
                    });
                });
            }
            if (paper && getByType("rect").length > 0) {
                $('<li>').text("Rect objects:").appendTo(ul);
                getByType("rect").forEach((rect, index) => {
                    let li_rect = $('<li>').text("Rect " + (index+1) + ": " + rect.attr('x') + "," + rect.attr('y') + " (" + rect.attr('width') + "x" + rect.attr('height') + ")").appendTo(ul);
                    li_rect.on("click", e=>{
                        rect.node.click();
                    });
                });
            }
            if (paper && getByType("circle").length > 0) {
                $('<li>').text("Circle objects:").appendTo(ul);
                getByType("circle").forEach((circle, index) => {
                    let li_circle = $('<li>').text("Circle " + (index+1) + ": " + circle.attr('cx') + "," + circle.attr('cy') + " (r=" + circle.attr('r') + ")").appendTo(ul);
                    li_circle.on("click", e=>{
                        circle.node.click();
                    });
                });
            }
            if (paper && getByType("ellipse").length > 0) {
                $('<li>').text("Ellipse objects:").appendTo(ul);
                getByType("ellipse").forEach((ellipse, index) => {
                    let li_ellipse = $('<li>').text("Ellipse " + (index+1) + ": " + ellipse.attr('cx') + "," + ellipse.attr('cy') + " (rx=" + ellipse.attr('rx') + ", ry=" + ellipse.attr('ry') + ")").appendTo(ul);
                    li_ellipse.on("click", e=>{
                        ellipse.node.click();
                    });
                });
            }
            if (paper && getByType("set").length > 0) {
                $('<li>').text("Set objects:").appendTo(ul);
                getByType("set").forEach((set, index) => {
                    let li_set = $('<li>').text("Set " + (index+1) + ": " + set.items.length + " items").appendTo(ul);
                    li_set.on("click", e=>{
                        set.node.click();
                    });
                });
            }
            if (paper && getByType("g").length > 0) {
                $('<li>').text("Group objects:").appendTo(ul);
                getByType("g").forEach((group, index) => {
                    let li_group = $('<li>').text("Group " + (index+1) + ": " + group.items.length + " items").appendTo(ul);
                    li_group.on("click", e=>{
                        group.node.click();
                    });
                });
            }
            if (paper && getByType("symbol").length > 0) {
                $('<li>').text("Symbol objects:").appendTo(ul);
                getByType("symbol").forEach((symbol, index) => {
                    let li_symbol = $('<li>').text("Symbol " + (index+1) + ": " + symbol.attr('id')).appendTo(ul);
                    li_symbol.on("click", e=>{
                        symbol.node.click();
                    });
                });
            }
            if (paper && getByType("text").length > 0) {
                $('<li>').text("Text objects:").appendTo(ul);
                getByType("text").forEach((text, index) => {
                    let li_text = $('<li>').text("Text " + (index+1) + ": " + text.attr('text')).appendTo(ul);
                    li_text.on("click", e=>{
                        text.node.click();
                    });
                });
            }

        }

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

        function getByType(type) {
            return seats.filter(seat => seat.attr("type") === type);
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
                listAllObjects();
            }
        }).addClass("button-primary").appendTo(btn_grp);

        // add button to add a text to the drawing area
        $('<button>').text("Add text").on("click", e=>{
            if (!is_plan_loaded) {
                _setInfo("Please create a seating plan first");
                return;
            }
            let drag_start_x = 50;
            let drag_start_y = 40;
            let text = paper.text(50, 50, "New Text");
            seats.push(text);

            text.attr({
                "font-size": 20,
                "font-family": "Arial",
                "fill": "#000",
                //"stroke": "#fff",
                //"stroke-width": 1
            });
            text.drag((dx,dy,x,y,elem)=>{ // onmove
                text.attr("x", dx+50);
                text.attr("y", dy+50);
            }, (x,y,elem)=>{ // onstart
                text.attr("opacity", 0.7);
            }, (x,y,elem)=>{ // onend
                text.attr("opacity", 1);
            });
            _setInfo("Text added");
            text.drag((dx,dy,x,y,elem)=>{ // onmove
                text.attr("x", dx+drag_start_x);
                text.attr("y", dy+drag_start_y);
            }, (x,y,elem)=>{ // onstart
                text.attr("opacity", 0.7);
            }, (x,y,elem)=>{ // onend
                drag_start_x = text.attr("x");
                drag_start_y = text.attr("y");
                text.attr("opacity", 1);
            });
            text.dblclick(()=>{
                _setInfo("Text selected");
                CONTROL_PANEL.html(""); // clear control panel
                let div_mask =$('<div>').appendTo(CONTROL_PANEL);
                let div_buttons = $('<div>').appendTo(CONTROL_PANEL);

                let text_id = paper.getById(text.id);
                let text_content = $('<input type="text" placeholder="Text content">').appendTo(div_mask);
                let text_color = $('<input type="color">').appendTo(div_mask);

                text_color.val(text.attr("fill"));
                text_content.val(text.attr("text"));

                let text_save = $('<button>').text("Save").appendTo(div_buttons);
                // add listener to change the color immediately
                text_color.on("input", e=>{
                    text.attr("fill", text_color.val());
                    //text.attr("stroke", text_color.val() == '#ffffff' ? '#000' : '#fff');
                    _setInfo("Text color changed");
                });
                // add listener to change the text immediately
                text_content.on("input", e=>{
                    text.attr("text", text_content.val().trim());
                    _setInfo("Text content changed");
                });
                // save the text and color
                text_save.on("click", e=>{
                    console.log("save");
                    text_content.val(text_content.val().trim());
                    text.attr("text", text_content.val());
                    let c = text_color.val();
                    text.attr("fill", c);
                    //text.attr("stroke", c == '#ffffff' ? '#000' : '#fff');
                    _setInfo("Text saved");
                    listAllObjects();
                });
                let text_delete = $('<button>').text("Delete").appendTo(div_buttons);
                text_delete.on("click", e=>{
                    if (confirm("Delete text?")) {
                        paper.getById(text.id).remove();
                        _setInfo("Text deleted");
                        listAllObjects();
                    }
                });
                // store the values for cancel
                let orig_text_content = text_content.val();
                let orig_text_color = text_color.val();
                let text_cancel = $('<button>').text("Cancel").appendTo(div_buttons);
                text_cancel.on("click", e=>{
                    text.attr("text", orig_text_content);
                    text.attr("fill", orig_text_color);
                    CONTROL_PANEL.html(""); // clear control panel
                    _setInfo("Text edit cancelled");
                    listAllObjects();
                });

            });
        }).addClass("button-primary").appendTo(btn_grp_2);

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

            circle.dblclick(()=>{
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
                    listAllObjects();
                });
                let seat_delete = $('<button>').text("Delete").appendTo(CONTROL_PANEL);
                seat_delete.on("click", e=>{
                    if (confirm("Delete seat?")) {
                        seats.splice(seat_id, 1);
                        seat.svgObject.remove();
                        _setInfo("Seat deleted");
                        listAllObjects();
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